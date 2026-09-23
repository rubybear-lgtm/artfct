<?php

use App\Enums\PaymentStatus;
use App\Enums\TeamRole;
use App\Models\McpActivity;
use App\Models\McpConnection;
use App\Models\OAuthRefreshToken;
use App\Models\Team;
use App\Models\User;
use App\Services\Auth\OrgJwtService;
use Illuminate\Support\Facades\Http;

/**
 * Runs the PKCE flow end to end and returns the token response payload.
 *
 * @return array{access_token: string, refresh_token: string}
 */
function mcpOAuthTokens(Team $team, User $user): array
{
    $verifier = str_repeat('m', 64);
    configureSigning(testSigningKey());
    $parameters = oauthParameters(oauthChallenge($verifier));

    $authorization = test()->actingAs($user)->post('/oauth/authorize', [
        ...$parameters,
        'decision' => 'approve',
        'team' => $team->slug,
    ]);
    parse_str((string) parse_url($authorization->headers->get('Location'), PHP_URL_QUERY), $query);

    $token = test()->postJson('/oauth/token', [
        'grant_type' => 'authorization_code',
        'code' => $query['code'],
        'client_id' => 'artfct-cli',
        'redirect_uri' => $parameters['redirect_uri'],
        'code_verifier' => $verifier,
    ])->assertOk();

    return ['access_token' => $token->json('access_token'), 'refresh_token' => $token->json('refresh_token')];
}

function mcpAdminOf(Team $team): User
{
    return memberOfTeam($team, TeamRole::Admin);
}

function mcpInitialize(string $token)
{
    return test()->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [],
    ]);
}

test('a refresh token rotates and replaying the old one revokes the connection', function () {
    $team = Team::factory()->create();
    $user = mcpAdminOf($team);
    $tokens = mcpOAuthTokens($team, $user);

    $refreshed = $this->postJson('/oauth/token', [
        'grant_type' => 'refresh_token',
        'refresh_token' => $tokens['refresh_token'],
        'client_id' => 'artfct-cli',
    ])->assertOk();

    expect($refreshed->json('refresh_token'))->not->toBe($tokens['refresh_token']);
    mcpInitialize($refreshed->json('access_token'))->assertOk();

    $this->postJson('/oauth/token', [
        'grant_type' => 'refresh_token',
        'refresh_token' => $tokens['refresh_token'],
        'client_id' => 'artfct-cli',
    ])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');

    expect(McpConnection::query()->where('team_id', $team->id)->whereNull('revoked_at')->count())->toBe(0);
    mcpInitialize($refreshed->json('access_token'))->assertUnauthorized();
});

test('a refresh token issued to one client cannot be redeemed by another', function () {
    $team = Team::factory()->create();
    $tokens = mcpOAuthTokens($team, mcpAdminOf($team));

    $this->postJson('/oauth/token', [
        'grant_type' => 'refresh_token',
        'refresh_token' => $tokens['refresh_token'],
        'client_id' => 'someone-else',
    ])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
});

test('revoking the refresh token stops the access token and the refresh path', function () {
    $team = Team::factory()->create();
    $tokens = mcpOAuthTokens($team, mcpAdminOf($team));
    mcpInitialize($tokens['access_token'])->assertOk();

    $this->postJson('/oauth/revoke', ['token' => $tokens['refresh_token']])->assertOk();

    mcpInitialize($tokens['access_token'])->assertUnauthorized();
    $this->postJson('/oauth/token', [
        'grant_type' => 'refresh_token',
        'refresh_token' => $tokens['refresh_token'],
        'client_id' => 'artfct-cli',
    ])->assertStatus(400);
});

test('revoking an unknown token answers the same as a known one', function () {
    $this->postJson('/oauth/revoke', ['token' => 'not-a-real-token'])->assertOk();
});

test('an expired access token is rejected', function () {
    $team = Team::factory()->create();
    configureSigning(testSigningKey());
    $user = mcpAdminOf($team);
    $expired = OrgJwtService::default()->mint($team, $user, TeamRole::Admin, -60)['token'];

    mcpInitialize($expired)->assertUnauthorized();
});

test('a token minted for another issuer or audience is rejected', function (string $setting) {
    $team = Team::factory()->create();
    $token = remoteMcpToken($team);
    mcpInitialize($token)->assertOk();

    config([$setting => 'https://elsewhere.example']);

    mcpInitialize($token)->assertUnauthorized();
})->with([
    'wrong issuer' => 'services.org_jwt.issuer',
    'wrong audience' => 'services.org_jwt.audience',
]);

test('malformed and foreign bearer tokens are rejected', function (string $token) {
    configureSigning(testSigningKey());

    mcpInitialize($token)->assertUnauthorized();
})->with([
    'garbage' => 'not-a-jwt',
    'unsigned' => 'eyJhbGciOiJub25lIiwidHlwIjoiSldUIn0.eyJvcmciOiJ4In0.',
    'wrong signature' => 'eyJhbGciOiJSUzI1NiJ9.eyJvcmciOiJ4In0.AAAA',
    'empty segments' => '..',
]);

test('malformed JSON-RPC bodies never cause a server error', function (mixed $body) {
    $team = Team::factory()->create();
    $token = remoteMcpToken($team);

    $response = $this->withToken($token)->call('POST', '/mcp', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ], is_string($body) ? $body : json_encode($body));

    expect($response->getStatusCode())->toBeLessThan(500);
})->with([
    'not json' => '{{{',
    'empty' => '',
    'scalar' => '42',
    'null' => 'null',
    'list of scalars' => '[1,2,3]',
    'missing method' => [['jsonrpc' => '2.0', 'id' => 1]],
    'numeric method' => [['jsonrpc' => '2.0', 'id' => 1, 'method' => 7]],
    'wrong version' => [['jsonrpc' => '1.0', 'id' => 1, 'method' => 'initialize']],
    'params as string' => [['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => 'x']],
    'tool name as array' => [['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => ['x']]]],
    'unknown tool' => [['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'nope']]],
    'arguments as string' => [['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'get_usage', 'arguments' => 'x']]],
    'huge id' => [['jsonrpc' => '2.0', 'id' => str_repeat('a', 5000), 'method' => 'ping']],
    'deep nesting' => [['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'get_artifact', 'arguments' => ['id' => [[[[['x']]]]]]]]],
]);

test('tool arguments of the wrong type return a tool error, not a crash', function (string $tool, array $arguments) {
    $team = Team::factory()->create();
    $token = remoteMcpToken($team);
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake();

    $response = $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => $tool, 'arguments' => $arguments],
    ]);

    expect($response->getStatusCode())->toBeLessThan(500);
    Http::assertNothingSent();
})->with([
    'artifact id as array' => ['get_artifact', ['id' => ['x']]],
    'artifact id path traversal' => ['get_artifact', ['id' => '../../admin']],
    'search query as object' => ['search_artifacts', ['query' => ['a' => 'b']]],
    'collection name as array' => ['create_collection', ['name' => ['x']]],
]);

test('no bearer credential is persisted in activity, connections or refresh tokens', function () {
    $team = Team::factory()->create();
    $tokens = mcpOAuthTokens($team, mcpAdminOf($team));

    mcpInitialize($tokens['access_token'])->assertOk();
    $this->withToken($tokens['access_token'])->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call',
        'params' => ['name' => 'get_usage', 'arguments' => []],
    ])->assertOk();

    $stored = json_encode([
        McpActivity::query()->get()->toArray(),
        McpConnection::query()->get()->toArray(),
        OAuthRefreshToken::query()->get()->toArray(),
    ], JSON_THROW_ON_ERROR);

    expect($stored)->not->toContain($tokens['access_token'])->not->toContain($tokens['refresh_token']);
    expect(OAuthRefreshToken::query()->value('token_hash'))->not->toBe($tokens['refresh_token']);
});

test('OAuth and MCP entry points never write bearer credentials to application logs', function () {
    $logPath = tempnam(sys_get_temp_dir(), 'mcp-security-log');
    expect($logPath)->not->toBeFalse();

    config([
        'logging.default' => 'mcp-security-test',
        'logging.channels.mcp-security-test' => [
            'driver' => 'single',
            'path' => $logPath,
            'replace_placeholders' => true,
        ],
    ]);
    app()->forgetInstance('log');

    $team = Team::factory()->create();
    $tokens = mcpOAuthTokens($team, mcpAdminOf($team));
    mcpInitialize($tokens['access_token'])->assertOk();

    $refreshed = $this->postJson('/oauth/token', [
        'grant_type' => 'refresh_token',
        'refresh_token' => $tokens['refresh_token'],
        'client_id' => 'artfct-cli',
    ])->assertOk();
    $this->postJson('/oauth/revoke', ['token' => $refreshed->json('refresh_token')])->assertOk();

    $written = file_get_contents($logPath);
    @unlink($logPath);

    expect($written)->not->toContain($tokens['access_token'])
        ->not->toContain($tokens['refresh_token'])
        ->not->toContain($refreshed->json('access_token'))
        ->not->toContain($refreshed->json('refresh_token'));
});

test('a past-due workspace keeps reading artifacts but cannot deploy', function () {
    configureArtifactLinks();

    $team = Team::factory()->create(['payment_status' => PaymentStatus::PastDue]);
    $token = remoteMcpToken($team);
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake(['worker.test/v1/artifacts/'.ARTIFACT_LINK_ID => Http::response([
        'id' => ARTIFACT_LINK_ID, 'tier' => 'permanent', 'entrypoint' => 'index.html',
        'created_at' => '2026-09-20T00:00:00Z', 'expires_at' => null, 'title' => 'Still readable',
    ], 200)]);

    $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'get_artifact', 'arguments' => ['id' => ARTIFACT_LINK_ID]],
    ])->assertOk()->assertJsonPath('result.structuredContent.title', 'Still readable');

    $this->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call',
        'params' => ['name' => 'deploy_to_canvas', 'arguments' => ['html' => '<h1>x</h1>']],
    ])->assertOk()
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0._meta.artfct.errorCode', 'quota_exceeded');
});

test('two organizations never see each others MCP activity or connections', function () {
    $teamA = Team::factory()->create();
    $teamB = Team::factory()->create();
    $tokenA = remoteMcpToken($teamA);
    $tokenB = OrgJwtService::default()->mint($teamB, mcpAdminOf($teamB), TeamRole::Admin)['token'];

    foreach ([$tokenA, $tokenB] as $token) {
        $this->withToken($token)->postJson('/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'get_usage', 'arguments' => []],
        ])->assertOk();
    }

    $activityA = McpActivity::query()->where('team_id', $teamA->id)->get();
    $activityB = McpActivity::query()->where('team_id', $teamB->id)->get();
    expect($activityA)->not->toBeEmpty()->and($activityB)->not->toBeEmpty();
    expect($activityA->pluck('id')->intersect($activityB->pluck('id')))->toBeEmpty();
    expect(McpConnection::query()->where('team_id', $teamA->id)->pluck('id')
        ->intersect(McpConnection::query()->where('team_id', $teamB->id)->pluck('id')))->toBeEmpty();

    $memberA = User::factory()->create();
    $this->actingAs($memberA)->get(route('teams.mcp-connections.index', $teamB))->assertNotFound();
});
