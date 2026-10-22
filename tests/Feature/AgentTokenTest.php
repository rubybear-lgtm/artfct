<?php

use App\Enums\McpScope;
use App\Enums\OrgTokenKind;
use App\Enums\TeamRole;
use App\Models\McpConnection;
use App\Models\OrgToken;
use App\Models\Team;
use App\Models\User;
use App\Services\Auth\OrgJwtService;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{token: string, id: int, jti: string, bot: User}
 */
function mintAgentToken(Team $team, $user, array $scopes, array $extra = []): array
{
    configureOrgJwt();

    $botName = $extra['bot_name'] ?? 'Hoku';
    $botResponse = test()->actingAs($user)->postJson("/settings/teams/{$team->slug}/bots", [
        'name' => $botName,
        'scopes' => $scopes,
        ...array_intersect_key($extra, array_flip(['ttl_seconds'])),
    ])->assertCreated();

    $raw = $botResponse->json('token');
    $claims = (array) JWT::decode($raw, new Key(ORG_JWT_TEST_PUBLIC_KEY, 'RS256'));
    $bot = User::query()->where('name', $botName)->where('is_bot', true)->sole();
    $orgToken = OrgToken::query()->where('jti', $claims['jti'])->sole();

    return [
        'token' => $raw,
        'id' => $orgToken->id,
        'jti' => (string) $claims['jti'],
        'bot' => $bot,
    ];
}

function agentMcpInitialize(string $token)
{
    return test()->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2025-11-25',
            'clientInfo' => ['name' => 'test-bot', 'version' => '1.0'],
        ],
    ]);
}

test('a member creates an agent token shown once with scopes hashed as jti only', function () {
    $team = Team::factory()->create();
    $member = memberOfTeam($team, TeamRole::Member);
    $minted = mintAgentToken($team, $member, [
        McpScope::ArtifactsRead->value,
        McpScope::ArtifactsDeploy->value,
    ]);

    $stored = OrgToken::query()->findOrFail($minted['id']);
    expect($stored->kind)->toBe(OrgTokenKind::Agent)
        ->and($stored->name)->toBe('Hoku')
        ->and($stored->user_id)->toBe($minted['bot']->id)
        ->and($stored->team_id)->toBe($team->id)
        ->and($stored->scopes)->toBe([
            McpScope::ArtifactsRead->value,
            McpScope::ArtifactsDeploy->value,
        ])
        ->and($stored->jti)->toBe($minted['jti'])
        ->and($stored->getAttributes())->not->toHaveKey('token');

    $claims = OrgJwtService::default()->verify($minted['token']);
    expect($claims['org_id'])->toBe($team->slug)
        ->and($claims['user_id'])->toBe((string) $minted['bot']->id)
        ->and($claims['scope'])->toBe('artifacts:read artifacts:deploy');

    expect(McpConnection::query()->where('credential_jti', $minted['jti'])->exists())->toBeTrue();
});

test('an agent token authenticates mcp without a browser and reports bot attribution', function () {
    $team = Team::factory()->create(['slug' => 'artfct-dev']);
    $member = memberOfTeam($team, TeamRole::Member);
    $minted = mintAgentToken($team, $member, [
        McpScope::ArtifactsRead->value,
        McpScope::CollectionsRead->value,
        McpScope::UsageRead->value,
    ]);

    $initialize = agentMcpInitialize($minted['token'])->assertOk();
    $sessionId = $initialize->headers->get('MCP-Session-Id');

    $connection = test()->withToken($minted['token'])
        ->withHeader('MCP-Session-Id', $sessionId)
        ->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => ['name' => 'get_connection', 'arguments' => []],
        ])->assertOk()
        ->json('result.structuredContent');

    expect($connection['authenticated'])->toBeTrue()
        ->and($connection['organization'])->toBe('artfct-dev')
        ->and($connection['user_id'])->toBe((string) $minted['bot']->id)
        ->and($connection['credential_source'])->toBe('agent_token')
        ->and($connection['agent'])->toBe(['name' => 'Hoku', 'bot' => true])
        ->and($connection['scopes'])->toContain(McpScope::ArtifactsRead->value)
        ->and($connection['scopes'])->not->toContain(McpScope::ArtifactsDeploy->value);

    expect(OrgToken::query()->findOrFail($minted['id'])->last_used_at)->not->toBeNull();
});

test('revoking an agent token immediately returns 401 on mcp', function () {
    configureOrgJwt();
    Http::fake([
        'https://worker.test/v1/internal/revocations' => Http::response(['revoked' => true], 200),
    ]);

    $team = Team::factory()->create();
    $member = memberOfTeam($team, TeamRole::Member);
    $minted = mintAgentToken($team, $member, [McpScope::ArtifactsRead->value]);

    agentMcpInitialize($minted['token'])->assertOk();

    test()->actingAs($member)->deleteJson("/settings/teams/{$team->slug}/tokens/{$minted['id']}")
        ->assertOk();

    agentMcpInitialize($minted['token'])
        ->assertUnauthorized()
        ->assertJsonPath('error', 'Token revoked');
});

test('an agent token cannot reach another team', function () {
    $teamA = Team::factory()->create(['slug' => 'team-a']);
    $teamB = Team::factory()->create(['slug' => 'team-b']);
    $member = memberOfTeam($teamA, TeamRole::Member);
    $teamB->memberships()->create(['user_id' => $member->id, 'role' => TeamRole::Member]);

    $minted = mintAgentToken($teamA, $member, [McpScope::ArtifactsRead->value]);

    $initialize = agentMcpInitialize($minted['token'])->assertOk();
    $sessionId = $initialize->headers->get('MCP-Session-Id');

    $connection = test()->withToken($minted['token'])
        ->withHeader('MCP-Session-Id', $sessionId)
        ->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => ['name' => 'get_connection', 'arguments' => []],
        ])->assertOk()
        ->json('result.structuredContent');

    expect($connection['organization'])->toBe('team-a')
        ->and($connection['organization'])->not->toBe('team-b');

    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake(['worker.test/v1/orgs/*' => Http::response(['artifacts' => [], 'next_cursor' => null])]);

    test()->withToken($minted['token'])
        ->withHeader('MCP-Session-Id', $sessionId)
        ->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/call',
            'params' => ['name' => 'list_artifacts', 'arguments' => []],
        ])->assertOk()->assertJsonPath('result.isError', false);

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/v1/orgs/team-a/artifacts')
        && ! str_contains($request->url(), '/v1/orgs/team-b/'));
});

test('agent token scopes are enforced on mcp tools', function () {
    $team = Team::factory()->create();
    $member = memberOfTeam($team, TeamRole::Member);
    $minted = mintAgentToken($team, $member, [McpScope::ArtifactsRead->value]);

    $response = test()->withToken($minted['token'])->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => [
            'name' => 'deploy_artifact',
            'arguments' => ['html' => '<h1>nope</h1>'],
        ],
    ])->assertOk()
        ->assertJsonPath('result.isError', true);

    expect($response->json('result.content.0.text'))->toStartWith('insufficient_scope:');
});

test('a viewer cannot mint an agent token', function () {
    configureOrgJwt();
    $team = Team::factory()->create();
    $viewer = memberOfTeam($team, TeamRole::Viewer);

    test()->actingAs($viewer)->postJson("/settings/teams/{$team->slug}/bots", [
        'name' => 'sneaky',
        'scopes' => [McpScope::ArtifactsRead->value],
    ])->assertForbidden();

    expect(OrgToken::query()->where('team_id', $team->id)->count())->toBe(0);
});

test('viewer role scope caps exclude mutation scopes', function () {
    expect(McpScope::forRole(TeamRole::Viewer))->toBe([
        McpScope::ArtifactsRead->value,
        McpScope::CollectionsRead->value,
        McpScope::UsageRead->value,
    ])->and(McpScope::forRole(TeamRole::Member))->toBe(McpScope::values());
});

test('unknown scopes are rejected when minting an agent token', function () {
    configureOrgJwt();
    $team = Team::factory()->create();
    $member = memberOfTeam($team, TeamRole::Member);

    test()->actingAs($member)->postJson("/settings/teams/{$team->slug}/bots", [
        'name' => 'Hoku',
        'scopes' => ['artifacts:read', 'not:a-scope'],
    ])->assertInvalid(['scopes.1']);
});

test('agent token mint rejects empty scopes', function () {
    configureOrgJwt();
    $team = Team::factory()->create();
    $member = memberOfTeam($team, TeamRole::Member);

    test()->actingAs($member)->postJson("/settings/teams/{$team->slug}/bots", [
        'name' => 'Hoku',
        'scopes' => [],
    ])->assertInvalid(['scopes']);
});

test('api tokens without kind keep working for rest and mcp', function () {
    configureOrgJwt();
    $team = Team::factory()->create();
    $admin = memberOfTeam($team, TeamRole::Admin);

    $response = test()->actingAs($admin)->postJson("/settings/teams/{$team->slug}/tokens", [
        'name' => 'ci-deploy',
        'role' => 'admin',
    ])->assertCreated();

    $stored = OrgToken::query()->findOrFail($response->json('id'));
    expect($stored->kind)->toBe(OrgTokenKind::Api)
        ->and($stored->scopes)->toBeNull();

    agentMcpInitialize($response->json('token'))->assertOk();
});

test('tokens page exposes agent token props', function () {
    $team = Team::factory()->create();
    $admin = memberOfTeam($team, TeamRole::Admin);
    $bot = User::factory()->bot()->create(['name' => 'Hoku']);
    $team->memberships()->create(['user_id' => $bot->id, 'role' => TeamRole::Member]);
    OrgToken::factory()->agent()->for($team)->for($bot)->create(['name' => 'Hoku MCP']);

    test()->actingAs($admin)->get(route('teams.tokens.index', $team))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('teams/tokens')
            ->where('canCreate', true)
            ->has('mcpEndpoint')
            ->has('scopeOptions')
            ->has('defaultScopes')
            ->has('bots')
            ->where('tokens.0.name', 'Hoku MCP')
            ->where('tokens.0.kind', 'agent')
            ->missing('tokens.0.jti'));
});

test('optional expiry is stored on the agent token', function () {
    $team = Team::factory()->create();
    $member = memberOfTeam($team, TeamRole::Member);
    $minted = mintAgentToken($team, $member, [McpScope::ArtifactsRead->value], [
        'ttl_seconds' => 3600,
    ]);

    $stored = OrgToken::query()->findOrFail($minted['id']);
    expect($stored->expires_at->between(now()->addMinutes(59), now()->addMinutes(61)))->toBeTrue();
});

test('additional agent tokens can be minted for an existing bot', function () {
    configureOrgJwt();
    $team = Team::factory()->create();
    $admin = memberOfTeam($team, TeamRole::Admin);
    $first = mintAgentToken($team, $admin, [McpScope::ArtifactsRead->value]);

    $response = test()->actingAs($admin)->postJson("/settings/teams/{$team->slug}/tokens", [
        'name' => 'Hoku spare',
        'kind' => 'agent',
        'bot_id' => $first['bot']->id,
        'scopes' => [McpScope::ArtifactsRead->value, McpScope::UsageRead->value],
    ])->assertCreated();

    expect($response->json('kind'))->toBe('agent');
    $stored = OrgToken::query()->findOrFail($response->json('id'));
    expect($stored->user_id)->toBe($first['bot']->id)
        ->and($stored->scopes)->toBe([McpScope::ArtifactsRead->value, McpScope::UsageRead->value]);
});
