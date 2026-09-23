<?php

use App\Contracts\ArtifactContentSource;
use App\Enums\AuditEventType;
use App\Enums\TeamRole;
use App\Models\AuditEvent;
use App\Models\Collection;
use App\Models\CollectionArtifact;
use App\Models\McpActivity;
use App\Models\McpConnection;
use App\Models\Team;
use App\Models\User;
use App\Services\Artifacts\HttpArtifactContentSource;
use App\Services\Auth\OrgJwtService;
use App\Services\Billing\FakeUsage;
use App\Services\Billing\RealUsage;
use App\Services\Billing\UsageContract;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/*
 * Following an artifact `view_url` the way a browser would is the only way to
 * tell a link that works from one that merely looks right — which is exactly
 * what a string-prefix assertion could not do for `deploy_to_canvas`.
 *
 * Dispatch is by origin, because the origin is what decides which server
 * actually answers. A URL on the Worker's public origin is a real HTTP fetch
 * (through the app's HTTP client, so a fake stands in for the Worker), and the
 * Worker is the only server that can serve a KV artifact. A URL on the app's
 * own origin is dispatched through the app's router, so the open route's
 * authorization, tier resolution and token minting all actually run. An
 * unknown origin is refused rather than guessed at. The fragment is never sent
 * — no browser sends one — so the follow exercises the server's half of the
 * link and the test decrypts the rest.
 *
 * @return array{url: string, dispatched_to: string, status: int, body: string, location: string|null}
 */
function followArtifactViewUrl(string $viewUrl, ?User $as = null): array
{
    $host = (string) parse_url($viewUrl, PHP_URL_HOST);
    $workerHost = parse_url((string) config('services.worker.base_url'), PHP_URL_HOST);
    $appHost = parse_url(route('console.open', ['team' => 'team', 'artifactId' => 'artifact']), PHP_URL_HOST);

    if (is_string($workerHost) && $host === $workerHost) {
        $response = Http::get($viewUrl);

        return [
            'url' => $viewUrl,
            'dispatched_to' => 'worker',
            'status' => $response->status(),
            'body' => (string) $response->body(),
            'location' => null,
        ];
    }

    if ($host !== $appHost) {
        throw new RuntimeException("Refusing to follow a view_url on an unknown origin ({$host}).");
    }

    $path = (string) parse_url($viewUrl, PHP_URL_PATH);
    $query = (string) parse_url($viewUrl, PHP_URL_QUERY);
    $response = ($as !== null ? test()->actingAs($as) : test())
        ->get($path.($query === '' ? '' : '?'.$query));

    return [
        'url' => $viewUrl,
        'dispatched_to' => 'app',
        'status' => $response->getStatusCode(),
        'body' => (string) $response->getContent(),
        'location' => $response->headers->get('Location'),
    ];
}

/** The base64url form the `deploy_to_canvas` tool posts its body in. */
function base64UrlDecode(string $value): string
{
    return (string) base64_decode(strtr($value, '-_', '+/'), true);
}

/**
 * Decrypt a posted `deploy_to_canvas` body with the share fragment from the
 * link the tool returned: the fragment is the artifact's access mechanism, so
 * the right fragment must yield the payload the tool was given.
 *
 * @param  array<string, mixed>  $posted  The create request body the tool sent.
 */
function decryptCanvasPayload(array $posted, string $fragment): string|false
{
    $sealed = base64UrlDecode((string) $posted['body_ciphertext_b64']);
    $iv = base64UrlDecode((string) $posted['body_iv_b64']);
    $tag = substr($sealed, -16);

    return openssl_decrypt(
        substr($sealed, 0, -16),
        'aes-256-gcm',
        hash('sha256', $fragment, true),
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
    );
}

test('serves the native Streamable HTTP MCP transport with bearer authentication', function () {
    $team = Team::factory()->create();
    $token = remoteMcpToken($team);

    $initialize = $this->withHeaders([
        'Authorization' => 'Bearer '.$token,
        'Accept' => 'application/json, text/event-stream',
    ])->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2025-11-25',
            'clientInfo' => ['name' => 'remote-test', 'version' => '1.0.0'],
            'capabilities' => [],
        ],
    ]);

    $initialize->assertOk()
        ->assertHeader('Content-Type', 'application/json')
        ->assertHeader('MCP-Session-Id')
        ->assertJsonPath('result.protocolVersion', '2025-11-25')
        ->assertJsonPath('result.serverInfo.name', 'artfct')
        ->assertJsonPath('result.serverInfo.version', '1.0.0')
        ->assertJsonPath('result.capabilities.tools.listChanged', false)
        ->assertJsonPath('result.instructions', 'Publish HTML artifacts to the authenticated workspace, then search, retrieve and organize them. deploy_to_canvas is deprecated: it creates anonymous expiring artifacts the workspace cannot search.')
        ->assertJsonMissingPath('result.capabilities.resources');

    expect(McpConnection::query()
        ->where('team_id', $team->id)
        ->where('client_name', 'remote-test')
        ->where('transport', 'streamable-http')
        ->exists())->toBeTrue();

    $sessionId = $initialize->headers->get('MCP-Session-Id');
    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$token,
        'Accept' => 'application/json',
        'MCP-Session-Id' => $sessionId,
    ])->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 2,
        'method' => 'tools/list',
        'params' => [],
    ])->assertOk()
        ->assertJsonPath('result.tools.0.name', 'deploy_artifact')
        ->assertJsonPath('result.tools.1.name', 'deploy_to_canvas')
        ->assertJsonPath('result.tools.2.name', 'search_artifacts')
        ->assertJsonPath('result.tools.3.name', 'get_connection')
        ->assertJsonPath('result.tools.0._meta.artfct.contractVersion', '1.0.0')
        ->assertJsonPath('result.tools.0._meta.artfct.requiredScopes.0', 'artifacts:deploy');
});

test('remote MCP negotiates supported protocol versions and rejects unsupported ones', function () {
    $team = Team::factory()->create();
    $token = remoteMcpToken($team);

    $response = $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => ['protocolVersion' => '2024-11-05'],
    ])->assertOk()->assertJsonPath('result.protocolVersion', '2024-11-05');

    $response = $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 2,
        'method' => 'initialize',
        'params' => ['protocolVersion' => '2099-01-01'],
    ])->assertOk()
        ->assertJsonPath('error.code', -32602)
        ->assertJsonPath('error.message', 'Unsupported protocol version');
});

test('stateless Streamable HTTP advertises POST-only session behavior', function () {
    $team = Team::factory()->create();
    remoteMcpToken($team);

    $this->get('/mcp')
        ->assertStatus(405)
        ->assertHeader('Allow', 'POST');

    $this->delete('/mcp')
        ->assertStatus(405)
        ->assertHeader('Allow', 'POST');
});

test('hosted MCP refuses a session presented with a different bearer credential', function () {
    $firstTeam = Team::factory()->create();
    $secondTeam = Team::factory()->create();
    configureSigning(testSigningKey());
    $firstToken = OrgJwtService::default()->mint(
        $firstTeam,
        memberOfTeam($firstTeam, TeamRole::Admin),
        TeamRole::Admin,
    )['token'];
    $secondToken = OrgJwtService::default()->mint(
        $secondTeam,
        memberOfTeam($secondTeam, TeamRole::Admin),
        TeamRole::Admin,
    )['token'];

    $firstInitialize = $this->withToken($firstToken)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => ['clientInfo' => ['name' => 'tenant-isolation-test']],
    ])->assertOk();
    $firstSessionId = $firstInitialize->headers->get('MCP-Session-Id');

    $secondResponse = $this->withHeaders([
        'Authorization' => 'Bearer '.$secondToken,
        'MCP-Session-Id' => $firstSessionId,
    ])->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 2,
        'method' => 'tools/call',
        'params' => ['name' => 'get_connection', 'arguments' => []],
    ]);
    $secondResponse->assertStatus(400)
        ->assertJsonPath('error.code', -32001)
        ->assertJsonPath('error.data.artfct.errorCode', 'session_expired')
        ->assertJsonPath('error.data.artfct.retryable', false)
        ->assertJsonPath('error.data.artfct.nextAction', 'initialize');

    $secondInitialize = $this->withToken($secondToken)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 3,
        'method' => 'initialize',
        'params' => ['protocolVersion' => '2025-11-25'],
    ])->assertOk();

    $this->withHeaders([
        'Authorization' => 'Bearer '.$secondToken,
        'MCP-Session-Id' => $secondInitialize->headers->get('MCP-Session-Id'),
    ])->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 4,
        'method' => 'tools/call',
        'params' => ['name' => 'get_connection', 'arguments' => []],
    ])->assertOk()
        ->assertJsonPath('result.structuredContent.organization', $secondTeam->slug);
});

test('remote MCP refuses an expired session with stable reinitialization guidance', function () {
    $team = Team::factory()->create();
    $token = remoteMcpToken($team);

    $initialize = $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => ['protocolVersion' => '2025-11-25'],
    ])->assertOk();
    $sessionId = (string) $initialize->headers->get('MCP-Session-Id');
    Cache::forget('mcp-session:'.$sessionId);

    $this->withHeaders([
        'Authorization' => 'Bearer '.$token,
        'MCP-Session-Id' => $sessionId,
    ])->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 2,
        'method' => 'tools/list',
        'params' => [],
    ])->assertStatus(400)
        ->assertJsonPath('error.code', -32001)
        ->assertJsonPath('error.message', 'MCP session is invalid or expired. Initialize a new session and retry.')
        ->assertJsonPath('error.data.artfct.errorCode', 'session_expired')
        ->assertJsonPath('error.data.artfct.nextAction', 'initialize');
});

test('remote MCP rate limits each credential and returns retry guidance', function () {
    $team = Team::factory()->create();
    $token = remoteMcpToken($team);
    $jti = OrgJwtService::default()->verify($token)['jti'];
    RateLimiter::clear('mcp:'.$jti);
    RateLimiter::clear('mcp-org:'.$team->slug);
    config(['auth.mcp_throttle_per_minute' => 1]);

    $payload = [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [],
    ];

    $this->withToken($token)->postJson('/mcp', $payload)->assertOk();

    $this->withToken($token)->postJson('/mcp', $payload)
        ->assertTooManyRequests()
        ->assertHeader('Retry-After')
        ->assertHeader('X-RateLimit-Limit', '1')
        ->assertJsonPath('jsonrpc', '2.0')
        ->assertJsonPath('id', 1)
        ->assertJsonPath('error.code', -32029)
        ->assertJsonPath('error.data.artfct.errorCode', 'rate_limit')
        ->assertJsonPath('error.data.artfct.retryable', true)
        ->assertJsonPath('error.data.artfct.nextAction', 'retry_after');
});

test('remote MCP rate limits connections collectively within an organization', function () {
    $team = Team::factory()->create();
    configureSigning(testSigningKey());
    $firstToken = OrgJwtService::default()->mint($team, memberOfTeam($team, TeamRole::Admin), TeamRole::Admin)['token'];
    $secondToken = OrgJwtService::default()->mint($team, memberOfTeam($team, TeamRole::Admin), TeamRole::Admin)['token'];
    $firstJti = OrgJwtService::default()->verify($firstToken)['jti'];
    $secondJti = OrgJwtService::default()->verify($secondToken)['jti'];
    RateLimiter::clear('mcp-org:'.$team->slug);
    RateLimiter::clear('mcp:'.$firstJti);
    RateLimiter::clear('mcp:'.$secondJti);
    config(['auth.mcp_throttle_per_minute' => 1]);

    $payload = [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [],
    ];

    $this->withToken($firstToken)->postJson('/mcp', $payload)->assertOk();
    $this->withToken($secondToken)->postJson('/mcp', $payload)
        ->assertTooManyRequests()
        ->assertHeader('Retry-After');
});

test('local MCP API calls share the organization and credential rate limits', function () {
    $team = Team::factory()->create();
    $token = remoteMcpToken($team);
    $jti = OrgJwtService::default()->verify($token)['jti'];
    RateLimiter::clear('mcp:'.$jti);
    RateLimiter::clear('mcp-org:'.$team->slug);
    config(['auth.mcp_throttle_per_minute' => 1]);
    config(['indexing.enabled' => true]);

    $this->withToken($token)
        ->postJson('/api/search', ['query' => 'dashboard'])
        ->assertOk();

    $this->withToken($token)
        ->postJson('/api/search', ['query' => 'dashboard'])
        ->assertTooManyRequests()
        ->assertHeader('Retry-After')
        ->assertHeader('X-RateLimit-Limit', '1')
        ->assertJsonPath('errorCode', 'rate_limit')
        ->assertJsonPath('retryable', true)
        ->assertJsonPath('nextAction', 'retry_after');
});

test('rejects unauthenticated remote MCP requests with discovery guidance', function () {
    $response = $this->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [],
    ])->assertUnauthorized();

    $response
        ->assertHeaderContains(
            'WWW-Authenticate',
            'resource_metadata="'.url('/.well-known/oauth-protected-resource/mcp').'"',
        )
        ->assertHeaderContains('Access-Control-Expose-Headers', 'WWW-Authenticate')
        ->assertJsonPath('error', 'Missing bearer token');
});

test('revoking a registered connection stops hosted MCP access immediately', function () {
    $team = Team::factory()->create();
    $token = remoteMcpToken($team);

    $registered = $this->withToken($token)->postJson('/api/mcp/connections', [
        'client_name' => 'hosted-client',
        'transport' => 'streamable-http',
    ])->assertCreated();

    McpConnection::query()
        ->where('public_id', $registered->json('connection_id'))
        ->update(['revoked_at' => now()]);

    $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [],
    ])->assertUnauthorized()->assertJsonPath('error', 'MCP connection revoked');
});

test('an expired registered connection stops hosted MCP access immediately', function () {
    $team = Team::factory()->create();
    $token = remoteMcpToken($team);

    $registered = $this->withToken($token)->postJson('/api/mcp/connections', [
        'client_name' => 'expired-client',
        'transport' => 'streamable-http',
    ])->assertCreated();

    McpConnection::query()
        ->where('public_id', $registered->json('connection_id'))
        ->update(['expires_at' => now()->subMinute()]);

    $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [],
    ])->assertUnauthorized()->assertJsonPath('error', 'MCP connection expired');
});

test('remote MCP tools honor bearer scopes', function () {
    $team = Team::factory()->create();
    $token = remoteMcpToken($team, TeamRole::Viewer);

    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$token,
        'Accept' => 'application/json',
    ])->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => [
            'name' => 'deploy_to_canvas',
            'arguments' => ['html' => '<h1>no</h1>'],
        ],
    ])->assertOk()
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0.type', 'text');

    expect($response->json('result.content.0.text'))->toStartWith('insufficient_scope:');

    expect(McpActivity::query()
        ->where('team_id', $team->id)
        ->where('tool', 'deploy_to_canvas')
        ->where('outcome', 'denied')
        ->exists())->toBeTrue();
});

test('remote MCP can permanently delete an artifact with the destructive scope', function () {
    $team = Team::factory()->create();
    $token = remoteMcpToken($team);
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake([
        'worker.test/v1/artifacts/artifact123' => Http::response('', 204),
    ]);

    $response = $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => [
            'name' => 'delete_artifact',
            'arguments' => ['id' => 'artifact123'],
        ],
    ])->assertOk();

    $response->assertJsonPath('result.structuredContent.id', 'artifact123')
        ->assertJsonPath('result.structuredContent.deleted', true);

    Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
        && $request->url() === 'https://worker.test/v1/artifacts/artifact123');

    expect(McpActivity::query()
        ->where('team_id', $team->id)
        ->where('tool', 'delete_artifact')
        ->where('outcome', 'success')
        ->exists())->toBeTrue();
});

test('remote MCP refuses destructive artifact deletion without the destructive scope', function () {
    $team = Team::factory()->create();
    $token = remoteMcpToken($team, TeamRole::Viewer);

    $response = $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => [
            'name' => 'delete_artifact',
            'arguments' => ['id' => 'artifact123'],
        ],
    ])->assertOk()
        ->assertJsonPath('result.isError', true);

    expect($response->json('result.content.0.text'))->toStartWith('insufficient_scope:');
    Http::assertNothingSent();
    expect(McpActivity::query()
        ->where('team_id', $team->id)
        ->where('tool', 'delete_artifact')
        ->where('outcome', 'denied')
        ->exists())->toBeTrue();
});

test('remote tool failures expose stable safe error metadata', function () {
    $team = Team::factory()->create();
    $token = remoteMcpToken($team);
    config(['services.worker.base_url' => null]);

    $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => [
            'name' => 'get_artifact',
            'arguments' => ['id' => 'artifact123'],
        ],
    ])->assertOk()
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0._meta.artfct.errorCode', 'configuration_error')
        ->assertJsonPath('result.content.0._meta.artfct.retryable', false)
        // `nextAction` is conditional (`app/Mcp/Support/McpErrorResponse.php:23`
        // only sets it when there is guidance), so its absence has to be
        // asserted: otherwise a client cannot tell "no action available" from
        // "the server stopped sending the field", and a later change could
        // start emitting `nextAction: null` without any test noticing.
        ->assertJsonMissingPath('result.content.0._meta.artfct.nextAction');
});

test('an error that has guidance does send nextAction', function () {
    $team = Team::factory()->create();
    $token = remoteMcpToken($team);
    $jti = OrgJwtService::default()->verify($token)['jti'];
    RateLimiter::clear('mcp:'.$jti);
    RateLimiter::clear('mcp-org:'.$team->slug);
    config(['auth.mcp_throttle_per_minute' => 1]);

    $payload = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []];

    $this->withToken($token)->postJson('/mcp', $payload)->assertOk();

    // The positive half of the same contract: the field is absent when there is
    // nothing to suggest, and present when there is.
    $this->withToken($token)->postJson('/mcp', $payload)
        ->assertTooManyRequests()
        ->assertJsonPath('error.data.artfct.nextAction', 'retry_after');
});

test('hosted activity uses the client identity captured during initialize', function () {
    $team = Team::factory()->create();
    $token = remoteMcpToken($team);

    $initialize = $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2025-11-25',
            'clientInfo' => ['name' => 'fixture-agent', 'version' => '9.1'],
        ],
    ])->assertOk();

    $this->withToken($token)
        ->withHeader('MCP-Session-Id', $initialize->headers->get('MCP-Session-Id'))
        ->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => ['name' => 'get_connection', 'arguments' => []],
        ])->assertOk();

    expect(McpActivity::query()->latest('id')->value('client_name'))->toBe('fixture-agent');
    expect(McpActivity::query()->latest('id')->value('protocol_version'))->toBe('2025-11-25');
});

test('compatibility failures identify client transport protocol version and remediation', function () {
    $team = Team::factory()->create();
    $token = remoteMcpToken($team);

    $initialize = $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2024-11-05',
            'clientInfo' => ['name' => 'compatibility-fixture', 'version' => '1.0.0'],
        ],
    ])->assertOk();

    $this->withToken($token)
        ->withHeader('MCP-Session-Id', $initialize->headers->get('MCP-Session-Id'))
        ->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => ['name' => 'deploy_artifact', 'arguments' => ['html' => '<h1>failure</h1>']],
        ])->assertOk()
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0._meta.artfct.client', 'compatibility-fixture')
        ->assertJsonPath('result.content.0._meta.artfct.transport', 'streamable-http')
        ->assertJsonPath('result.content.0._meta.artfct.protocolVersion', '2024-11-05')
        ->assertJsonPath('result.content.0._meta.artfct.retryable', false)
        ->assertJsonPath('result.content.0._meta.artfct.errorCode', 'configuration_error');
});

test('remote deployment encrypts the body and returns a shareable result', function () {
    $team = Team::factory()->create();
    $token = remoteMcpToken($team);
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake([
        'worker.test/v1/artifacts' => Http::response([
            'id' => 'artifact-123',
            'url' => 'https://artfct.dev/p/artifact-123',
            'tier' => 'public',
            'expires_at' => now()->addDay()->toIso8601String(),
            'title' => 'Remote test',
            'description' => 'Encrypted HTML preview on artfct.',
            'thumbnail' => 'https://artfct.dev/og-image.svg',
            'preview_blurred' => true,
        ], 201),
    ]);

    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$token,
        'Accept' => 'application/json',
    ])->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => [
            'name' => 'deploy_to_canvas',
            'arguments' => ['html' => '<title>Remote test</title><h1>Hello</h1>'],
        ],
    ])->assertOk();

    $response->assertJsonPath('result.structuredContent.id', 'artifact-123')
        ->assertJsonPath('result.structuredContent.organization', $team->slug);

    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), '/v1/artifacts')
        && $request->data()['body_ciphertext_b64'] !== '<title>Remote test</title><h1>Hello</h1>');

    expect(McpActivity::query()
        ->where('team_id', $team->id)
        ->where('tool', 'deploy_to_canvas')
        ->where('outcome', 'success')
        ->count())->toBe(1);
});

test('deploy_to_canvas hands back a link that resolves for the artifact it created, at every tier it accepts', function (string $tier) {
    configureArtifactLinks();

    $team = Team::factory()->create(['slug' => 'rub-367-canvas']);
    $token = remoteMcpToken($team);
    $member = memberOfTeam($team, TeamRole::Member);
    $html = '<title>Canvas preview</title><h1>preview</h1>';
    config([
        'services.worker.base_url' => 'https://worker.test',
        'app.public_base_url' => 'https://artfct.dev',
    ]);

    // The Worker exactly as production serves this tool's artifacts: the create
    // response addresses an anonymous KV record, `/p/{id}` renders that
    // record's preview shell, and every D1-backed read — the org content
    // endpoint the app's open route goes through — finds no row, because this
    // tool writes none. The real HTTP content source is bound deliberately: an
    // in-memory source is what let the old string-prefix assertion pass while
    // the link it blessed resolved to a 404 for real.
    app()->bind(ArtifactContentSource::class, fn (): HttpArtifactContentSource => HttpArtifactContentSource::default());

    Http::fake(function ($request) use ($tier) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);

        return match (true) {
            $path === '/v1/artifacts' => Http::response([
                'id' => ARTIFACT_LINK_ID,
                'url' => 'https://worker.test/p/'.ARTIFACT_LINK_ID,
                'tier' => $tier,
                'expires_at' => now()->addDay()->toIso8601String(),
                'title' => 'Canvas preview',
                'description' => 'Encrypted HTML preview on artfct.',
                'thumbnail' => 'https://artfct.dev/og-image.svg',
                'preview_blurred' => true,
            ], 201),
            // The KV path: the only thing that can serve this artifact.
            $path === '/p/'.ARTIFACT_LINK_ID => Http::response(
                '<!doctype html><title>Canvas preview</title><p>Waiting for the decryption key in the URL fragment.</p>',
                200,
            ),
            default => Http::response(['error' => ['code' => 'artifact_not_found']], 404),
        };
    });
    app()->bind(UsageContract::class, FakeUsage::class);

    $response = $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'deploy_to_canvas', 'arguments' => ['html' => $html, 'tier' => $tier]],
    ])->assertOk();

    $viewUrl = (string) $response->json('result.structuredContent.view_url');
    $followed = followArtifactViewUrl($viewUrl);

    // The link resolves, for the artifact the tool created, on the only origin
    // that has it: following it reaches the KV record's preview shell. This is
    // the assertion the prefix check only pretended to make.
    expect($followed['dispatched_to'])->toBe('worker')
        ->and($followed['url'])->toBe('https://worker.test/p/'.ARTIFACT_LINK_ID.'#'.substr($viewUrl, strpos($viewUrl, '#') + 1))
        ->and($followed['status'])->toBe(200)
        ->and($followed['body'])->toContain('Waiting for the decryption key in the URL fragment.');

    // And the app's open route is the dead end it always was for these
    // artifacts: the same member asking for the same id through the app gets a
    // 404, because the route reads D1 and there is no row. An app-route link
    // with a fragment appended to it can therefore never be the right answer.
    $throughTheApp = followArtifactViewUrl(
        route('console.open', ['team' => $team->slug, 'artifactId' => ARTIFACT_LINK_ID]),
        $member,
    );

    expect($throughTheApp['status'])->toBe(404)
        ->and($throughTheApp['location'])->toBeNull();

    // The fragment is not decoration: it is the key to the body the tool
    // actually posted, so the whole link — origin, path, fragment — is one
    // working artifact.
    $posted = null;
    foreach (Http::recorded() as [$request]) {
        if ($request->method() === 'POST' && str_ends_with((string) $request->url(), '/v1/artifacts')) {
            $posted = $request->data();
        }
    }

    $fragment = substr($viewUrl, strpos($viewUrl, '#') + 1);

    expect($posted)->toBeArray()
        ->and($fragment)->not->toBe('')
        ->and(decryptCanvasPayload($posted, $fragment))->toBe($html);

    // The canonical form is the resource's identity, not a link a person opens,
    // and the field is `view_url` — one name for one meaning.
    expect($response->json('result.structuredContent.canonical_url'))->toBe('https://worker.test/p/'.ARTIFACT_LINK_ID)
        ->and($response->json('result.structuredContent'))->not->toHaveKey('url');
    // The hosted tool accepts only public and secure, so `ephemeral` — the
    // local server's third tier — is exercised by the shared contract fixture
    // and the Rust suite instead of here; all three get the same link shape.
})->with(['public', 'secure']);

test('remote deployment deduplicates retries with the same request ID', function () {
    $team = Team::factory()->create();
    $token = remoteMcpToken($team);
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake([
        'worker.test/v1/artifacts' => Http::response([
            'id' => 'artifact-retried',
            'url' => 'https://artfct.dev/p/artifact-retried',
            'tier' => 'public',
            'expires_at' => now()->addDay()->toIso8601String(),
            'title' => 'Retried deployment',
            'description' => 'Encrypted HTML preview on artfct.',
            'thumbnail' => 'https://artfct.dev/og-image.svg',
            'preview_blurred' => true,
        ], 201),
    ]);

    $requestId = 'retry-'.Str::uuid();
    $firstInitialize = $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => ['protocolVersion' => '2025-11-25'],
    ])->assertOk();
    $secondInitialize = $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 2,
        'method' => 'initialize',
        'params' => ['protocolVersion' => '2025-11-25'],
    ])->assertOk();
    $payload = [
        'jsonrpc' => '2.0',
        'id' => 3,
        'method' => 'tools/call',
        'params' => [
            'name' => 'deploy_to_canvas',
            'arguments' => ['html' => '<h1>retry me</h1>'],
        ],
    ];

    $first = $this->withHeaders([
        'Authorization' => 'Bearer '.$token,
        'MCP-Request-Id' => $requestId,
        'MCP-Session-Id' => $firstInitialize->headers->get('MCP-Session-Id'),
    ])->postJson('/mcp', $payload)->assertOk();

    $second = $this->withHeaders([
        'Authorization' => 'Bearer '.$token,
        'MCP-Request-Id' => $requestId,
        'MCP-Session-Id' => $secondInitialize->headers->get('MCP-Session-Id'),
    ])->postJson('/mcp', $payload)->assertOk();

    $second->assertJsonPath('result.structuredContent.id', 'artifact-retried')
        ->assertJsonPath('result.structuredContent.view_url', $first->json('result.structuredContent.view_url'))
        // The idempotent replay carries the same link, and that link is a real
        // one — comparing two nulls would pass for the wrong reason.
        ->assertJsonMissingPath('result.structuredContent.url');

    // The replayed link is a real one, not two nulls comparing equal: a public
    // artifact gets the workspace's public URL, with the client-side share
    // fragment that unlocks it.
    expect((string) $first->json('result.structuredContent.view_url'))
        ->toStartWith('https://artfct.dev/p/artifact-retried#');

    expect($second->json('result.structuredContent.view_url'))
        ->toBe($first->json('result.structuredContent.view_url'));
    Http::assertSentCount(1);
    expect(McpActivity::query()
        ->where('team_id', $team->id)
        ->where('tool', 'deploy_to_canvas')
        ->where('outcome', 'duplicate')
        ->exists())->toBeTrue();

    $this->withHeaders([
        'Authorization' => 'Bearer '.$token,
        'MCP-Request-Id' => $requestId,
    ])->postJson('/mcp', [
        ...$payload,
        'params' => [
            ...$payload['params'],
            'arguments' => ['html' => '<h1>different payload</h1>'],
        ],
    ])->assertOk()
        ->assertJsonPath('result.content.0._meta.artfct.errorCode', 'idempotency_key_reused');
    Http::assertSentCount(1);
});

test('remote deployment refuses over-quota workspaces before contacting the worker', function () {
    $team = Team::factory()->create();
    $token = remoteMcpToken($team);
    /** @var FakeUsage $usage */
    $usage = app(UsageContract::class);
    $usage->setUsage($team->slug, storageBytes: PHP_INT_MAX, artifactsThisPeriod: 0);
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake();

    $response = $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => [
            'name' => 'deploy_to_canvas',
            'arguments' => ['html' => '<h1>quota</h1>'],
        ],
    ])->assertOk()
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0.type', 'text')
        ->assertJsonPath('result.content.0._meta.artfct.errorCode', 'quota_exceeded')
        ->assertJsonPath('result.content.0._meta.artfct.retryable', false)
        ->assertJsonPath('result.content.0._meta.artfct.nextAction', 'get_usage');

    expect($response->json('result.content.0.text'))->toContain('get_usage');

    Http::assertNothingSent();
    expect(McpActivity::query()
        ->where('team_id', $team->id)
        ->where('tool', 'deploy_to_canvas')
        ->where('outcome', 'quota_exceeded')
        ->exists())->toBeTrue();
});

test('remote usage returns customer-safe quota and render totals', function () {
    $team = Team::factory()->create();
    $token = remoteMcpToken($team);
    /** @var FakeUsage $usage */
    $usage = app(UsageContract::class);
    $usage->setUsage($team->slug, storageBytes: 800, artifactsThisPeriod: 4, renderMinutesThisPeriod: 12);

    $this->withHeaders([
        'Authorization' => 'Bearer '.$token,
        'Accept' => 'application/json',
    ])->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => [
            'name' => 'get_usage',
            'arguments' => [],
        ],
    ])->assertOk()
        ->assertJsonPath('result.structuredContent.organization', $team->slug)
        ->assertJsonPath('result.structuredContent.period.name', 'current')
        ->assertJsonPath('result.structuredContent.period.starts_at', now()->startOfMonth()->toIso8601String())
        ->assertJsonPath('result.structuredContent.period.resets_at', now()->startOfMonth()->addMonth()->toIso8601String())
        ->assertJsonPath('result.structuredContent.storage.used_bytes', 800)
        ->assertJsonPath('result.structuredContent.artifacts.used', 4)
        ->assertJsonPath('result.structuredContent.render_minutes.used', 12)
        ->assertJsonPath('result.structuredContent.can_create', true);
});

test('remote artifact retrieval returns metadata without bundle content', function () {
    configureArtifactLinks();

    $team = Team::factory()->create();
    $token = remoteMcpToken($team);
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake([
        'worker.test/v1/artifacts/'.ARTIFACT_LINK_ID => Http::response([
            'id' => ARTIFACT_LINK_ID,
            'tier' => 'permanent',
            'entrypoint' => 'index.html',
            'created_at' => '2026-09-20T00:00:00Z',
            'expires_at' => null,
            'title' => 'A report',
            'description' => 'A safe summary',
        ], 200),
    ]);

    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$token,
        'Accept' => 'application/json',
    ])->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => [
            'name' => 'get_artifact',
            'arguments' => ['id' => ARTIFACT_LINK_ID],
        ],
    ])->assertOk();

    $response->assertJsonPath('result.structuredContent.id', ARTIFACT_LINK_ID)
        ->assertJsonPath('result.structuredContent.title', 'A report')
        ->assertJsonMissingPath('result.structuredContent.content')
        ->assertJsonMissingPath('result.structuredContent.html');

    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), '/v1/artifacts/'.ARTIFACT_LINK_ID)
        && str_starts_with((string) $request->header('Authorization')[0], 'Bearer '));
});

test('remote collection listing is paginated and organization scoped', function () {
    $team = Team::factory()->create();
    $token = remoteMcpToken($team);
    $creator = memberOfTeam($team, TeamRole::Member);

    Collection::create([
        'team_id' => $team->id,
        'name' => 'Alpha reports',
        'description' => 'Approved reports',
        'canonical' => true,
        'created_by_user_id' => $creator->id,
    ]);
    Collection::create([
        'team_id' => $team->id,
        'name' => 'Beta reports',
        'created_by_user_id' => $creator->id,
    ]);

    $first = $this->withHeaders([
        'Authorization' => 'Bearer '.$token,
        'Accept' => 'application/json',
    ])->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => [
            'name' => 'list_collections',
            'arguments' => ['limit' => 1],
        ],
    ])->assertOk()
        ->assertJsonPath('result.structuredContent.collections.0.name', 'Alpha reports')
        ->assertJsonPath('result.structuredContent.collections.0.canonical', true)
        ->assertJsonMissingPath('result.structuredContent.collections.0.artifact_ids');

    $cursor = $first->json('result.structuredContent.next_cursor');
    expect($cursor)->toBeString()->not->toBeEmpty();

    $this->withHeaders([
        'Authorization' => 'Bearer '.$token,
        'Accept' => 'application/json',
    ])->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 2,
        'method' => 'tools/call',
        'params' => [
            'name' => 'list_collections',
            'arguments' => ['limit' => 1, 'cursor' => $cursor],
        ],
    ])->assertOk()
        ->assertJsonPath('result.structuredContent.collections.0.name', 'Beta reports')
        ->assertJsonPath('result.structuredContent.next_cursor', null);
});

test('collection mutations require the explicit write scope and stay organization scoped', function () {
    $team = Team::factory()->create();
    $token = remoteMcpToken($team);

    $created = $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => [
            'name' => 'create_collection',
            'arguments' => ['name' => 'Approved reports', 'description' => 'Curated output'],
        ],
    ])->assertOk()
        ->assertJsonPath('result.structuredContent.name', 'Approved reports')
        ->assertJsonPath('result.structuredContent.artifact_count', 0);

    $collectionId = $created->json('result.structuredContent.id');
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake([
        'worker.test/v1/artifacts/artifact123' => Http::response(['id' => 'artifact123', 'tier' => 'secure'], 200),
        'worker.test/v1/artifacts/ghost123' => Http::response(['error' => ['code' => 'artifact_not_found']], 404),
    ]);

    $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 4,
        'method' => 'tools/call',
        'params' => [
            'name' => 'add_collection_artifact',
            'arguments' => ['collection_id' => $collectionId, 'artifact_id' => 'ghost123'],
        ],
    ])->assertOk()
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0._meta.artfct.errorCode', 'artifact_not_found');
    expect(CollectionArtifact::query()->where('artifact_id', 'ghost123')->exists())->toBeFalse();

    $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 2,
        'method' => 'tools/call',
        'params' => [
            'name' => 'add_collection_artifact',
            'arguments' => ['collection_id' => $collectionId, 'artifact_id' => 'artifact123'],
        ],
    ])->assertOk()
        ->assertJsonPath('result.structuredContent.collection_id', $collectionId)
        ->assertJsonPath('result.structuredContent.artifact_id', 'artifact123');

    expect(CollectionArtifact::query()
        ->where('collection_id', $collectionId)
        ->where('artifact_id', 'artifact123')
        ->exists())->toBeTrue();

    $viewerToken = remoteMcpToken($team, TeamRole::Viewer);
    $response = $this->withToken($viewerToken)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 3,
        'method' => 'tools/call',
        'params' => [
            'name' => 'create_collection',
            'arguments' => ['name' => 'Not allowed'],
        ],
    ])->assertOk()->assertJsonPath('result.isError', true);

    expect($response->json('result.content.0.text'))->toStartWith('insufficient_scope:');

    expect(McpActivity::query()
        ->where('team_id', $team->id)
        ->where('tool', 'create_collection')
        ->where('outcome', 'denied')
        ->exists())->toBeTrue();
});

test('remote deployment reports an unavailable artifact service instead of an internal error', function () {
    $team = Team::factory()->create();
    $token = remoteMcpToken($team);
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake(['worker.test/*' => Http::response(['error' => 'unauthorized'], 401)]);
    app()->bind(UsageContract::class, RealUsage::class);

    $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => ['name' => 'deploy_to_canvas', 'arguments' => ['html' => '<h1>x</h1>']],
    ])->assertOk()
        ->assertJsonMissingPath('error')
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0._meta.artfct.errorCode', 'upstream_unavailable')
        ->assertJsonPath('result.content.0._meta.artfct.retryable', true);
});

test('deploy_artifact publishes a permanent org artifact in two steps', function () {
    configureArtifactLinks();

    $team = Team::factory()->create();
    $token = remoteMcpToken($team);
    $html = '<!doctype html><title>Q3 report</title><p>Summary</p>';
    $sha = hash('sha256', $html);
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake([
        'worker.test/v1/orgs/*/usage' => Http::response(['storage_bytes' => 0, 'artifacts_this_period' => 0], 200),
        'worker.test/v1/artifacts' => Http::response(['id' => ARTIFACT_LINK_ID, 'url' => 'https://worker.test/p/'.ARTIFACT_LINK_ID, 'tier' => 'secure', 'missing_files' => [$sha]], 201),
        'worker.test/v1/artifacts/'.ARTIFACT_LINK_ID.'/files/*' => Http::response('', 204),
    ]);
    app()->bind(UsageContract::class, FakeUsage::class);

    $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'deploy_artifact', 'arguments' => ['html' => $html]],
    ])->assertOk()
        ->assertJsonPath('result.structuredContent.id', ARTIFACT_LINK_ID)
        ->assertJsonPath('result.structuredContent.tier', 'secure')
        ->assertJsonPath('result.structuredContent.title', 'Q3 report')
        ->assertJsonPath('result.structuredContent.organization', $team->slug);

    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && str_ends_with((string) $request->url(), '/v1/artifacts')
        && $request['mode'] === 'permanent'
        && $request['manifest']['files'][0]['sha256'] === $sha
        && ! isset($request['body_ciphertext_b64']));
    Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
        && str_ends_with((string) $request->url(), '/v1/artifacts/'.ARTIFACT_LINK_ID."/files/{$sha}")
        && $request->body() === $html);
});

test('deploy_artifact skips the upload when the Worker already has the content', function () {
    configureArtifactLinks();

    $team = Team::factory()->create();
    $token = remoteMcpToken($team);
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake(['worker.test/v1/artifacts' => Http::response(['id' => ARTIFACT_LINK_ID, 'url' => 'https://worker.test/p/'.ARTIFACT_LINK_ID, 'tier' => 'secure', 'missing_files' => []], 200)]);
    app()->bind(UsageContract::class, FakeUsage::class);

    $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'deploy_artifact', 'arguments' => ['html' => '<p>same</p>', 'tier' => 'public']],
    ])->assertOk()->assertJsonPath('result.structuredContent.id', ARTIFACT_LINK_ID);

    Http::assertNotSent(fn ($request): bool => $request->method() === 'PUT');
});

test('deploy_artifact hands a secure artifact the apps own open route', function () {
    configureArtifactLinks();

    $team = Team::factory()->create(['slug' => 'rub-367-org']);
    $token = remoteMcpToken($team);
    $html = '<!doctype html><title>Signed report</title><p>Summary</p>';
    $sha = hash('sha256', $html);
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake([
        'worker.test/v1/artifacts' => Http::response(['id' => ARTIFACT_LINK_ID, 'url' => 'https://worker.test/p/'.ARTIFACT_LINK_ID, 'tier' => 'secure', 'missing_files' => [$sha]], 201),
        'worker.test/v1/artifacts/'.ARTIFACT_LINK_ID.'/files/*' => Http::response('', 204),
    ]);
    app()->bind(UsageContract::class, FakeUsage::class);

    $response = $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'deploy_artifact', 'arguments' => ['html' => $html]],
    ])->assertOk();

    expect($response->json('result.isError'))->toBeFalse()
        // Neither the Worker's raw `/p/{id}` URL — what a browser cannot present
        // a credential to — nor a minted token is handed to the agent. (Matched
        // on the host, not the full URL: the transport escapes the slashes, so
        // a full-URL `toContain` could never fail.)
        ->and($response->getContent())->not->toContain('worker.test')
        ->and($response->getContent())->not->toContain('token=')
        // The field is `view_url`, not `url`: one name for one meaning, shared
        // with the local server (`tests/Fixtures/artifact-view-link-contract.json`).
        ->and($response->json('result.structuredContent.url'))->toBeNull();

    // The link addresses the app's own open route for the credential's
    // workspace. What that route *does* when a person follows it — authorize
    // the viewer, resolve the tier, mint a link bound to this artifact, refuse
    // an outsider and a guest — is proved by behaviour in `a secure view_url
    // from the workspace tools opens for a member and is refused for everyone
    // else`, which follows this very URL. The string could not establish any of
    // it, and a string assertion is exactly what let `deploy_to_canvas`'s dead
    // link pass review.
    expect($response->json('result.structuredContent.organization'))->toBe($team->slug);

    expect(AuditEvent::query()
        ->where('team_id', $team->id)
        ->where('event_type', AuditEventType::ArtifactDeployed)
        ->where('actor', (string) OrgJwtService::default()->verify($token)['user_id'])
        ->where('target', 'artifact:'.ARTIFACT_LINK_ID)
        ->where('outcome', 'success')
        ->exists())->toBeTrue();
});

test('deploy_artifact hands a public artifact the workspaces public url without a token', function () {
    configureArtifactLinks();

    $team = Team::factory()->create(['slug' => 'rub-367-public']);
    $token = remoteMcpToken($team);
    $html = '<!doctype html><title>Public report</title><p>Summary</p>';
    $sha = hash('sha256', $html);
    config([
        'services.worker.base_url' => 'https://worker.test',
        'app.public_base_url' => 'https://artfct.dev',
    ]);
    // The Worker's own public base is deliberately neither the API host nor the
    // app's configured public base, so this asserts *which* URL the tool
    // returned rather than a value all three happen to agree on.
    Http::fake([
        'worker.test/v1/artifacts' => Http::response(['id' => ARTIFACT_LINK_ID, 'url' => 'https://staging.artfct.dev/p/'.ARTIFACT_LINK_ID, 'tier' => 'public', 'missing_files' => [$sha]], 201),
        'worker.test/v1/artifacts/'.ARTIFACT_LINK_ID.'/files/*' => Http::response('', 204),
    ]);
    app()->bind(UsageContract::class, FakeUsage::class);

    $response = $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'deploy_artifact', 'arguments' => ['html' => $html, 'tier' => 'public']],
    ])->assertOk();

    // A public artifact is served by the Worker to anyone, so the URL the
    // Worker itself published is the whole link: no token is minted for it, no
    // app round trip is asked of the reader, and no host is guessed.
    expect($response->json('result.structuredContent.view_url'))
        ->toBe('https://staging.artfct.dev/p/'.ARTIFACT_LINK_ID)
        ->and($response->json('result.structuredContent.view_url'))->not->toContain('worker.test')
        ->and($response->getContent())->not->toContain('token=')
        ->and($response->json('result.structuredContent.tier'))->toBe('public');
});

test('get_artifact hands a secure artifact the apps own open route', function () {
    configureArtifactLinks();

    $team = Team::factory()->create(['slug' => 'rub-367-fetch']);
    $token = remoteMcpToken($team);
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake([
        'worker.test/v1/artifacts/'.ARTIFACT_LINK_ID => Http::response([
            'id' => ARTIFACT_LINK_ID,
            'tier' => 'secure',
            'entrypoint' => 'index.html',
            'created_at' => '2026-09-20T00:00:00Z',
            'expires_at' => null,
            'title' => 'A report',
            'description' => 'A safe summary',
        ], 200),
    ]);

    $response = $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'get_artifact', 'arguments' => ['id' => ARTIFACT_LINK_ID]],
    ])->assertOk();

    expect($response->getContent())->not->toContain('worker.test')
        ->and($response->getContent())->not->toContain('token=');

    // The URL is the app's own open route — the behaviour of which, including
    // the artifact-bound token it mints on the click, `a secure view_url from
    // the workspace tools opens for a member and is refused for everyone else`
    // proves by following it.
    expect($response->json('result.structuredContent.url'))->toBeNull();
});

/*
 * The workspace tools hand back the app's open route, so "the link works" is a
 * claim about what that route does when a person clicks it: authorize the
 * viewer, resolve the tier, mint a token bound to that artifact, and refuse
 * everyone else. The URL string can establish none of that — and it is exactly
 * the string assertion that let `deploy_to_canvas`'s dead link pass review — so
 * this follows the link the returned view_url actually names.
 */
test('a secure view_url from the workspace tools opens for a member and is refused for everyone else', function (string $tool) {
    configureArtifactLinks();

    $team = Team::factory()->create(['slug' => 'rub-367-open']);
    $otherTeam = Team::factory()->create(['slug' => 'rub-367-elsewhere']);
    $token = remoteMcpToken($team);
    $member = memberOfTeam($team, TeamRole::Member);
    $outsider = memberOfTeam($otherTeam, TeamRole::Member);
    $html = '<!doctype html><title>Signed report</title><p>Summary</p>';
    $sha = hash('sha256', $html);
    config(['services.worker.base_url' => 'https://worker.test']);

    if ($tool === 'deploy_artifact') {
        Http::fake([
            'worker.test/v1/orgs/*/usage' => Http::response(['storage_bytes' => 0, 'artifacts_this_period' => 0], 200),
            'worker.test/v1/artifacts' => Http::response(['id' => ARTIFACT_LINK_ID, 'url' => 'https://worker.test/p/'.ARTIFACT_LINK_ID, 'tier' => 'secure', 'missing_files' => [$sha]], 201),
            'worker.test/v1/artifacts/'.ARTIFACT_LINK_ID.'/files/*' => Http::response('', 204),
        ]);
    } else {
        Http::fake([
            'worker.test/v1/artifacts/'.ARTIFACT_LINK_ID => Http::response([
                'id' => ARTIFACT_LINK_ID, 'tier' => 'secure', 'entrypoint' => 'index.html',
                'created_at' => '2026-09-20T00:00:00Z', 'expires_at' => null,
                'title' => 'Signed report', 'description' => 'A safe summary',
            ], 200),
        ]);
    }

    app()->bind(UsageContract::class, FakeUsage::class);

    // A workspace artifact lives in D1, which is what the open route reads.
    /** @var FakeArtifactContentSource $content */
    $content = app(ArtifactContentSource::class);
    $content->seed($team->slug, ARTIFACT_LINK_ID, $html, ['agent' => 'cursor', 'repo_url' => null, 'commit_sha' => null], 'secure');

    $response = $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => $tool === 'deploy_artifact'
            ? ['name' => $tool, 'arguments' => ['html' => $html, 'tier' => 'secure']]
            : ['name' => $tool, 'arguments' => ['id' => ARTIFACT_LINK_ID]],
    ])->assertOk();

    $viewUrl = (string) $response->json('result.structuredContent.view_url');

    // Shape that is genuinely the contract: the link is the app's route for the
    // caller's workspace, and it carries no credential — the token is minted
    // per click, for the viewer, and never handed to the agent.
    expect($viewUrl)->toBe(route('console.open', ['team' => $team->slug, 'artifactId' => ARTIFACT_LINK_ID]))
        ->and($viewUrl)->not->toContain('token=');

    // No session: refused, and nothing is minted for an anonymous reader.
    $anonymous = followArtifactViewUrl($viewUrl);

    expect($anonymous['status'])->toBe(302)
        ->and((string) $anonymous['location'])->not->toContain('token=')
        ->and((string) $anonymous['location'])->toContain('/login');

    // A member of another workspace: the same 404 as an id that exists
    // nowhere, and no token minted for them either.
    $refused = followArtifactViewUrl($viewUrl, $outsider);

    expect($refused['status'])->toBe(404)
        ->and($refused['location'])->toBeNull();

    // The member who was sent the link: the redirect carries a token bound to
    // this artifact, which is the thing that makes the link open.
    $followed = followArtifactViewUrl($viewUrl, $member);

    expect($followed['status'])->toBe(302);

    $location = (string) $followed['location'];

    expect($location)->toStartWith('https://'.$team->slug.'--'.ARTIFACT_LINK_ID.'.artfct.dev/p/'.ARTIFACT_LINK_ID.'?token=');

    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
    $minted = (string) ($query['token'] ?? '');
    $now = now()->timestamp;

    expect($minted)->not->toBe('')
        // A credential for this artifact...
        ->and(artifactTokenVerifies($minted, ARTIFACT_LINK_ID, ARTIFACT_LINK_SECRET, $now))->toBeTrue()
        // ...and not for another artifact id, nor under another secret.
        ->and(artifactTokenVerifies($minted, 'ffffffffffffffffffffffffffffffff', ARTIFACT_LINK_SECRET, $now))->toBeFalse()
        ->and(artifactTokenVerifies($minted, ARTIFACT_LINK_ID, 'some-other-secret', $now))->toBeFalse();
})->with(['deploy_artifact', 'get_artifact']);

test('get_artifact hands a public artifact the workspaces public url without a token', function () {
    configureArtifactLinks();

    $team = Team::factory()->create(['slug' => 'rub-367-fetch-public']);
    $token = remoteMcpToken($team);
    config([
        'services.worker.base_url' => 'https://worker.test',
        'app.public_base_url' => 'https://artfct.dev',
    ]);
    Http::fake([
        'worker.test/v1/artifacts/'.ARTIFACT_LINK_ID => Http::response([
            'id' => ARTIFACT_LINK_ID,
            'tier' => 'public',
            'entrypoint' => 'index.html',
            'created_at' => '2026-09-20T00:00:00Z',
            'expires_at' => null,
            'title' => 'A public report',
            'description' => 'A safe summary',
        ], 200),
    ]);

    $response = $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'get_artifact', 'arguments' => ['id' => ARTIFACT_LINK_ID]],
    ])->assertOk();

    expect($response->json('result.structuredContent.view_url'))
        ->toBe('https://artfct.dev/p/'.ARTIFACT_LINK_ID);
});

test('artifact links fail closed when no signing secret is configured', function () {
    config(['services.artifact_access.token_secret' => null]);

    $team = Team::factory()->create();
    $token = remoteMcpToken($team);
    $html = '<!doctype html><title>No secret</title><p>Summary</p>';
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake([
        'worker.test/v1/artifacts' => Http::response(['id' => ARTIFACT_LINK_ID, 'url' => 'https://worker.test/p/'.ARTIFACT_LINK_ID, 'tier' => 'secure', 'missing_files' => []], 201),
        'worker.test/v1/artifacts/'.ARTIFACT_LINK_ID => Http::response(['id' => ARTIFACT_LINK_ID, 'tier' => 'permanent', 'entrypoint' => 'index.html'], 200),
    ]);
    app()->bind(UsageContract::class, FakeUsage::class);

    $deploy = $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'deploy_artifact', 'arguments' => ['html' => $html]],
    ]);

    $deploy->assertOk()
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0.type', 'text')
        ->assertJsonPath('result.content.0._meta.artfct.errorCode', 'signed_link_unavailable')
        ->assertJsonPath('result.content.0._meta.artfct.retryable', false)
        ->assertJsonMissingPath('result.content.0._meta.artfct.nextAction')
        ->assertJsonMissingPath('result.structuredContent.view_url');

    $retrieval = $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call',
        'params' => ['name' => 'get_artifact', 'arguments' => ['id' => ARTIFACT_LINK_ID]],
    ]);

    $retrieval->assertOk()
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0._meta.artfct.errorCode', 'signed_link_unavailable')
        ->assertJsonPath('result.content.0._meta.artfct.retryable', false)
        ->assertJsonMissingPath('result.structuredContent.view_url');

    // No raw URL is offered as a substitute for the missing signed link.
    expect($deploy->getContent())->not->toContain('worker.test')
        ->and($retrieval->getContent())->not->toContain('worker.test')
        ->and($deploy->json('result.content.0.text'))->toContain('not configured')
        ->and(McpActivity::query()->where('team_id', $team->id)->where('outcome', 'signed_link_unavailable')->count())->toBe(2);
});

test('an artifact whose id cannot form an isolated hostname is refused rather than linked', function () {
    configureArtifactLinks();

    $team = Team::factory()->create();
    $token = remoteMcpToken($team);
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake([
        'worker.test/v1/artifacts/artifact123' => Http::response([
            'id' => 'artifact-123', 'tier' => 'permanent', 'entrypoint' => 'index.html',
        ], 200),
    ]);

    $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'get_artifact', 'arguments' => ['id' => 'artifact123']],
    ])->assertOk()
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0._meta.artfct.errorCode', 'signed_link_unavailable')
        ->assertJsonMissingPath('result.structuredContent.view_url');
});

test('deploy_artifact refuses over-quota workspaces before contacting the Worker', function () {
    $team = Team::factory()->create();
    $token = remoteMcpToken($team);
    /** @var FakeUsage $usage */
    $usage = app(UsageContract::class);
    $usage->setUsage($team->slug, storageBytes: PHP_INT_MAX, artifactsThisPeriod: 0);
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake();

    $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'deploy_artifact', 'arguments' => ['html' => '<p>x</p>']],
    ])->assertOk()
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0._meta.artfct.errorCode', 'quota_exceeded')
        ->assertJsonPath('result.content.0._meta.artfct.nextAction', 'get_usage');

    Http::assertNothingSent();
});

test('deploy_artifact maps Worker refusals to stable errors', function (int $status, array $body, string $code, bool $retryable) {
    $team = Team::factory()->create();
    $token = remoteMcpToken($team);
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake(['worker.test/v1/artifacts' => Http::response($body, $status)]);
    app()->bind(UsageContract::class, FakeUsage::class);

    $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'deploy_artifact', 'arguments' => ['html' => '<p>x</p>']],
    ])->assertOk()
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0._meta.artfct.errorCode', $code)
        ->assertJsonPath('result.content.0._meta.artfct.retryable', $retryable);
})->with([
    'rate limited' => [429, [], 'rate_limited', true],
    'worker quota' => [403, ['error' => ['code' => 'quota_exceeded']], 'quota_exceeded', false],
    'invalid manifest' => [422, ['error' => ['code' => 'validation_failed']], 'deployment_rejected', false],
]);

test('deploy_artifact requires the deploy scope', function () {
    $team = Team::factory()->create();
    $token = remoteMcpToken($team, TeamRole::Viewer);

    $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'deploy_artifact', 'arguments' => ['html' => '<p>x</p>']],
    ])->assertOk()
        ->assertJsonPath('result.isError', true);

    expect(McpActivity::query()->where('team_id', $team->id)->where('tool', 'deploy_artifact')->where('outcome', 'denied')->exists())->toBeTrue();
});

// The retrieval half of the telemetry chain, and the join the open-correlation
// depends on. ArtifactViewedHandler correlates a later open by matching
// `mcp_activities.actor` against the Worker event's `viewer_user_id`, and
// McpTelemetry writes `actor` from the credential's `user_id` claim. So if the
// claim were anything other than the user id, the correlation would never fire
// in production -- while handler tests that set `actor` through the factory
// would still pass. This asserts the value a real call actually records.
test('a real retrieval records the artifact and the actor the correlation matches on', function () {
    configureArtifactLinks();

    $team = Team::factory()->create();

    configureSigning(testSigningKey());
    $user = memberOfTeam($team, TeamRole::Admin);
    $token = OrgJwtService::default()->mint($team, $user, TeamRole::Admin)['token'];

    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake([
        'worker.test/v1/artifacts/'.ARTIFACT_LINK_ID => Http::response([
            'id' => ARTIFACT_LINK_ID,
            'tier' => 'permanent',
            'entrypoint' => 'index.html',
            'created_at' => '2026-09-20T00:00:00Z',
            'expires_at' => null,
            'title' => 'A report',
            'description' => 'A safe summary',
        ], 200),
    ]);

    $this->withHeaders([
        'Authorization' => 'Bearer '.$token,
        'Accept' => 'application/json',
    ])->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => ['name' => 'get_artifact', 'arguments' => ['id' => ARTIFACT_LINK_ID]],
    ])->assertOk();

    $activity = McpActivity::query()
        ->where('team_id', $team->id)
        ->where('tool', 'get_artifact')
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->artifact_id)->toBe(ARTIFACT_LINK_ID, 'the retrieval must record which artifact was retrieved')
        ->and($activity->outcome)->toBe('success')
        ->and($activity->actor)->toBe((string) $user->id, 'actor must be the user id the open-correlation matches against');
});
