<?php

use App\Enums\TeamRole;
use App\Models\Collection;
use App\Models\CollectionArtifact;
use App\Models\McpActivity;
use App\Models\McpConnection;
use App\Models\Team;
use App\Services\Auth\OrgJwtService;
use App\Services\Billing\FakeUsage;
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
        ->assertJsonPath('result.instructions', 'Publish encrypted HTML artifacts and search the authenticated workspace.')
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
        ->assertJsonPath('result.tools.0.name', 'deploy_to_canvas')
        ->assertJsonPath('result.tools.1.name', 'search_artifacts')
        ->assertJsonPath('result.tools.2.name', 'get_connection')
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
        ->assertJsonPath('result.content.0._meta.artfct.retryable', false);
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
    $team = Team::factory()->create();
    $token = remoteMcpToken($team);
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake([
        'worker.test/v1/artifacts/artifact123' => Http::response([
            'id' => 'artifact-123',
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
            'arguments' => ['id' => 'artifact123'],
        ],
    ])->assertOk();

    $response->assertJsonPath('result.structuredContent.id', 'artifact-123')
        ->assertJsonPath('result.structuredContent.title', 'A report')
        ->assertJsonMissingPath('result.structuredContent.content')
        ->assertJsonMissingPath('result.structuredContent.html');

    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), '/v1/artifacts/artifact123')
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
