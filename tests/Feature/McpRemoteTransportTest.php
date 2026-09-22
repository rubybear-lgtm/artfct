<?php

use App\Enums\TeamRole;
use App\Models\Collection;
use App\Models\CollectionArtifact;
use App\Models\McpActivity;
use App\Models\McpConnection;
use App\Models\Team;
use App\Services\Auth\OrgJwtService;
use App\Services\Billing\FakeUsage;
use App\Services\Billing\RealUsage;
use App\Services\Billing\UsageContract;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

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

test('hosted MCP resolves organization from the bearer credential, not the session header', function () {
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
    $secondResponse->assertOk()
        ->assertJsonPath('result.structuredContent.organization', $secondTeam->slug);
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

    expect($response->headers->get('WWW-Authenticate'))->toContain('oauth-protected-resource');
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
    $payload = [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => [
            'name' => 'deploy_to_canvas',
            'arguments' => ['html' => '<h1>retry me</h1>'],
        ],
    ];

    $first = $this->withHeaders([
        'Authorization' => 'Bearer '.$token,
        'MCP-Request-Id' => $requestId,
    ])->postJson('/mcp', $payload)->assertOk();

    $second = $this->withHeaders([
        'Authorization' => 'Bearer '.$token,
        'MCP-Request-Id' => $requestId,
    ])->postJson('/mcp', $payload)->assertOk();

    $second->assertJsonPath('result.structuredContent.id', 'artifact-retried')
        ->assertJsonPath('result.structuredContent.url', $first->json('result.structuredContent.url'));
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

test('deploy_artifact returns a signed link on the artifact isolated origin', function () {
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
        // The Worker's raw `/p/{id}` URL is exactly what a browser cannot
        // present a credential to, so its host must not appear in the result.
        // (Matched on the host, not the full URL: the transport escapes the
        // slashes, so a full-URL `toContain` could never fail.)
        ->and($response->getContent())->not->toContain('worker.test');

    $url = parse_url((string) $response->json('result.structuredContent.url'));

    expect($url)->toBeArray()
        ->and($url['scheme'] ?? null)->toBe('https')
        // The tenant in the host comes from the credential's workspace, and is
        // what binds the link to the org the Worker authorizes.
        ->and($url['host'] ?? null)->toBe('rub-367-org--'.ARTIFACT_LINK_ID.'.artfct.dev')
        ->and($url['path'] ?? null)->toBe('/p/'.ARTIFACT_LINK_ID);

    parse_str((string) ($url['query'] ?? ''), $query);
    $minted = (string) ($query['token'] ?? '');
    $now = now()->timestamp;

    expect($minted)->not->toBe('')
        ->and(artifactTokenVerifies($minted, ARTIFACT_LINK_ID, ARTIFACT_LINK_SECRET, $now))->toBeTrue()
        ->and(artifactTokenVerifies($minted, str_repeat('f', 32), ARTIFACT_LINK_SECRET, $now))->toBeFalse()
        ->and(artifactTokenVerifies($minted, ARTIFACT_LINK_ID, 'some-other-secret', $now))->toBeFalse();

    expect($response->json('result.structuredContent.organization'))->toBe($team->slug);
});

test('get_artifact returns a signed link on the artifact isolated origin', function () {
    configureArtifactLinks();

    $team = Team::factory()->create(['slug' => 'rub-367-fetch']);
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

    $response = $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'get_artifact', 'arguments' => ['id' => ARTIFACT_LINK_ID]],
    ])->assertOk();

    expect($response->json('result.isError'))->toBeFalse()
        ->and($response->getContent())->not->toContain('worker.test');

    $url = parse_url((string) $response->json('result.structuredContent.url'));

    expect($url)->toBeArray()
        ->and($url['scheme'] ?? null)->toBe('https')
        ->and($url['host'] ?? null)->toBe('rub-367-fetch--'.ARTIFACT_LINK_ID.'.artfct.dev')
        ->and($url['path'] ?? null)->toBe('/p/'.ARTIFACT_LINK_ID);

    parse_str((string) ($url['query'] ?? ''), $query);
    $minted = (string) ($query['token'] ?? '');
    $now = now()->timestamp;

    expect($minted)->not->toBe('')
        ->and(artifactTokenVerifies($minted, ARTIFACT_LINK_ID, ARTIFACT_LINK_SECRET, $now))->toBeTrue()
        ->and(artifactTokenVerifies($minted, str_repeat('f', 32), ARTIFACT_LINK_SECRET, $now))->toBeFalse()
        ->and(artifactTokenVerifies($minted, ARTIFACT_LINK_ID, 'some-other-secret', $now))->toBeFalse();
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
        ->assertJsonMissingPath('result.structuredContent.url');

    $retrieval = $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call',
        'params' => ['name' => 'get_artifact', 'arguments' => ['id' => ARTIFACT_LINK_ID]],
    ]);

    $retrieval->assertOk()
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0._meta.artfct.errorCode', 'signed_link_unavailable')
        ->assertJsonPath('result.content.0._meta.artfct.retryable', false)
        ->assertJsonMissingPath('result.structuredContent.url');

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
        ->assertJsonMissingPath('result.structuredContent.url');
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
