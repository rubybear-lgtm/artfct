<?php

use App\Contracts\UsageContract;
use App\Enums\McpScope;
use App\Enums\OrgTokenKind;
use App\Enums\Plan;
use App\Enums\TeamRole;
use App\Models\OAuthBotInstallation;
use App\Models\OAuthClient;
use App\Models\OrgToken;
use App\Models\Team;
use App\Models\User;
use App\Services\Billing\FakeUsage;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{
 *     admin: User,
 *     team: Team,
 *     client_id: string,
 *     redirect_uri: string,
 *     verifier: string,
 *     parameters: array<string, string|null>
 * }
 */
function botInstallOAuthFixture(array $scopes = [
    'artifacts:read',
    'artifacts:deploy',
    'collections:read',
    'usage:read',
]): array
{
    configureSigning(testSigningKey());
    configureOrgJwt();

    $admin = User::factory()->create(['name' => 'Consent Admin']);
    $team = Team::factory()->create(['plan' => Plan::Team, 'slug' => 'bot-install-team']);
    $team->memberships()->create(['user_id' => $admin->id, 'role' => TeamRole::Admin]);
    $admin->forceFill(['current_team_id' => $team->id])->save();

    $redirectUri = 'https://client.example.test/oauth/callback';
    $registration = test()->postJson('/oauth/register', [
        'client_name' => 'Grok Bot Harness',
        'redirect_uris' => [$redirectUri],
    ])->assertCreated();
    $clientId = $registration->json('client_id');
    $verifier = str_repeat('b', 64);
    $parameters = oauthParameters(oauthChallenge($verifier), [
        'client_id' => $clientId,
        'redirect_uri' => $redirectUri,
        'scope' => implode(' ', $scopes),
    ]);

    return [
        'admin' => $admin,
        'team' => $team,
        'client_id' => $clientId,
        'redirect_uri' => $redirectUri,
        'verifier' => $verifier,
        'parameters' => $parameters,
    ];
}

/**
 * @param  array<string, mixed>  $extra
 * @return array{access_token: string, refresh_token: string, code: string}
 */
function completeBotInstallOAuth(User $admin, Team $team, array $parameters, string $clientId, string $redirectUri, string $verifier, array $extra): array
{
    $authorization = test()->actingAs($admin)->post('/oauth/authorize', [
        ...$parameters,
        'decision' => 'approve',
        'team' => $team->slug,
        ...$extra,
    ])->assertRedirect();

    parse_str((string) parse_url((string) $authorization->headers->get('Location'), PHP_URL_QUERY), $query);
    expect($query['code'] ?? null)->toBeString()->not->toBeEmpty();

    $token = test()->postJson('/oauth/token', [
        'grant_type' => 'authorization_code',
        'code' => $query['code'],
        'client_id' => $clientId,
        'redirect_uri' => $redirectUri,
        'code_verifier' => $verifier,
    ])->assertOk()
        ->assertJsonPath('organization', $team->slug)
        ->assertJsonStructure(['access_token', 'refresh_token']);

    return [
        'access_token' => $token->json('access_token'),
        'refresh_token' => $token->json('refresh_token'),
        'code' => $query['code'],
    ];
}

function mcpGetConnection(string $accessToken): array
{
    $initialize = test()->withToken($accessToken)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2025-11-25',
            'clientInfo' => ['name' => 'Grok Bot Harness', 'version' => '0.0.1'],
        ],
    ])->assertOk();

    return test()->withToken($accessToken)
        ->withHeader('MCP-Session-Id', (string) $initialize->headers->get('MCP-Session-Id'))
        ->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => ['name' => 'get_connection', 'arguments' => []],
        ])->assertOk()
        ->json('result.structuredContent');
}

/**
 * Seed a manual agent-token bot without depending on AgentTokenTest helpers.
 *
 * @param  list<string>  $scopes
 * @return array{token: string, id: int, bot: User}
 */
function seedManualAgentBot(Team $team, User $admin, string $botName, array $scopes): array
{
    $response = test()->actingAs($admin)->postJson("/settings/teams/{$team->slug}/bots", [
        'name' => $botName,
        'scopes' => $scopes,
    ])->assertCreated();

    $raw = $response->json('token');
    $bot = User::query()->where('name', $botName)->where('is_bot', true)->sole();
    $orgToken = OrgToken::query()->where('user_id', $bot->id)->where('kind', OrgTokenKind::Agent)->latest('id')->firstOrFail();

    return [
        'token' => $raw,
        'id' => $orgToken->id,
        'bot' => $bot,
    ];
}

function agentInitialize(string $token)
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

test('consent page offers bot install options to a non-viewer human', function () {
    $fixture = botInstallOAuthFixture();

    test()->actingAs($fixture['admin'])
        ->get('/oauth/authorize?'.http_build_query(array_diff_key($fixture['parameters'], array_flip(['consent_token']))))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('oauth/authorize')
            ->where('canInstallAsBot', true)
            ->has('bots', 0));
});

test('oauth create-bot install mints an agent credential for the bot not the human', function () {
    $fixture = botInstallOAuthFixture();

    $tokens = completeBotInstallOAuth(
        $fixture['admin'],
        $fixture['team'],
        $fixture['parameters'],
        $fixture['client_id'],
        $fixture['redirect_uri'],
        $fixture['verifier'],
        ['bot_mode' => 'create', 'bot_name' => 'Grok Install Bot'],
    );

    $bot = User::query()->where('name', 'Grok Install Bot')->where('is_bot', true)->sole();
    expect($bot->email)->toBeNull()
        ->and($bot->belongsToTeam($fixture['team']))->toBeTrue()
        ->and(OAuthBotInstallation::query()->where('team_id', $fixture['team']->id)->where('oauth_client_id', $fixture['client_id'])->count())->toBe(1);

    $stored = OrgToken::query()->where('user_id', $bot->id)->where('kind', OrgTokenKind::Agent)->latest('id')->first();
    expect($stored)->not->toBeNull()
        ->and($stored->user_id)->toBe($bot->id)
        ->and($stored->user_id)->not->toBe($fixture['admin']->id);

    $connection = mcpGetConnection($tokens['access_token']);
    expect($connection['authenticated'])->toBeTrue()
        ->and($connection['organization'])->toBe('bot-install-team')
        ->and($connection['user_id'])->toBe((string) $bot->id)
        ->and($connection['credential_source'])->toBe('agent_token')
        ->and($connection['agent'])->toBe(['name' => 'Grok Install Bot', 'bot' => true])
        ->and($connection['scopes'])->toContain(McpScope::ArtifactsRead->value)
        ->and($connection['scopes'])->toContain(McpScope::ArtifactsDeploy->value);
});

test('deny consent creates no bot and no installation', function () {
    $fixture = botInstallOAuthFixture();

    test()->actingAs($fixture['admin'])->post('/oauth/authorize', [
        ...$fixture['parameters'],
        'decision' => 'deny',
        'team' => $fixture['team']->slug,
        'bot_mode' => 'create',
        'bot_name' => 'Should Not Exist',
    ])->assertRedirect();

    expect(User::query()->where('name', 'Should Not Exist')->exists())->toBeFalse()
        ->and(OAuthBotInstallation::query()->count())->toBe(0)
        ->and(OrgToken::query()->count())->toBe(0);
});

test('reconnect create is idempotent and does not rotate a seeded hoku-like bot', function () {
    $fixture = botInstallOAuthFixture();

    $hoku = seedManualAgentBot($fixture['team'], $fixture['admin'], 'Hoku Seed', [
        McpScope::ArtifactsRead->value,
    ]);
    $hokuBotId = $hoku['bot']->id;

    $first = completeBotInstallOAuth(
        $fixture['admin'],
        $fixture['team'],
        $fixture['parameters'],
        $fixture['client_id'],
        $fixture['redirect_uri'],
        $fixture['verifier'],
        ['bot_mode' => 'create', 'bot_name' => 'Reconnect Bot'],
    );
    $bot = User::query()->where('name', 'Reconnect Bot')->where('is_bot', true)->sole();

    $verifier2 = str_repeat('c', 64);
    $parameters2 = oauthParameters(oauthChallenge($verifier2), [
        'client_id' => $fixture['client_id'],
        'redirect_uri' => $fixture['redirect_uri'],
        'scope' => $fixture['parameters']['scope'],
    ]);
    $second = completeBotInstallOAuth(
        $fixture['admin'],
        $fixture['team'],
        $parameters2,
        $fixture['client_id'],
        $fixture['redirect_uri'],
        $verifier2,
        ['bot_mode' => 'create', 'bot_name' => 'Reconnect Bot Again'],
    );

    expect(User::query()->where('name', 'Reconnect Bot')->where('is_bot', true)->count())->toBe(1)
        ->and(User::query()->where('name', 'Reconnect Bot Again')->exists())->toBeFalse()
        ->and(OAuthBotInstallation::query()->where('team_id', $fixture['team']->id)->where('oauth_client_id', $fixture['client_id'])->count())->toBe(1)
        ->and(User::query()->find($hokuBotId)?->name)->toBe('Hoku Seed')
        ->and(OrgToken::query()->find($hoku['id'])?->revoked_at)->toBeNull();

    $connection = mcpGetConnection($second['access_token']);
    expect($connection['user_id'])->toBe((string) $bot->id)
        ->and($connection['agent']['name'])->toBe('Reconnect Bot');

    // First access token may still work until expiry; installation reuse is the guarantee.
    expect($first['access_token'])->not->toBe($second['access_token']);

    agentInitialize($hoku['token'])->assertOk();
});

test('a second oauth client can link a distinct bot in the same workspace', function () {
    $fixture = botInstallOAuthFixture();

    completeBotInstallOAuth(
        $fixture['admin'],
        $fixture['team'],
        $fixture['parameters'],
        $fixture['client_id'],
        $fixture['redirect_uri'],
        $fixture['verifier'],
        ['bot_mode' => 'create', 'bot_name' => 'Bot Alpha'],
    );
    $alpha = User::query()->where('name', 'Bot Alpha')->where('is_bot', true)->sole();

    $redirectUri = 'https://second.example.test/oauth/callback';
    $clientId = test()->postJson('/oauth/register', [
        'client_name' => 'Second Install',
        'redirect_uris' => [$redirectUri],
    ])->assertCreated()->json('client_id');
    $verifier = str_repeat('d', 64);
    $parameters = oauthParameters(oauthChallenge($verifier), [
        'client_id' => $clientId,
        'redirect_uri' => $redirectUri,
        'scope' => $fixture['parameters']['scope'],
    ]);

    $existing = User::factory()->bot()->create(['name' => 'Bot Beta']);
    $fixture['team']->memberships()->create(['user_id' => $existing->id, 'role' => TeamRole::Member]);

    $tokens = completeBotInstallOAuth(
        $fixture['admin'],
        $fixture['team'],
        $parameters,
        $clientId,
        $redirectUri,
        $verifier,
        ['bot_mode' => 'link', 'bot_id' => (string) $existing->id],
    );

    $connection = mcpGetConnection($tokens['access_token']);
    expect($connection['user_id'])->toBe((string) $existing->id)
        ->and($connection['agent'])->toBe(['name' => 'Bot Beta', 'bot' => true])
        ->and(OAuthBotInstallation::query()->where('team_id', $fixture['team']->id)->count())->toBe(2)
        ->and($alpha->refresh()->belongsToTeam($fixture['team']))->toBeTrue();
});

test('revoking the oauth connection removes only that installation access', function () {
    $fixture = botInstallOAuthFixture();
    Http::fake([
        'https://worker.test/v1/internal/revocations' => Http::response(['revoked' => true], 200),
    ]);
    config(['services.worker.base_url' => 'https://worker.test']);

    $hoku = seedManualAgentBot($fixture['team'], $fixture['admin'], 'Hoku Keep', [
        McpScope::ArtifactsRead->value,
    ]);

    $tokens = completeBotInstallOAuth(
        $fixture['admin'],
        $fixture['team'],
        $fixture['parameters'],
        $fixture['client_id'],
        $fixture['redirect_uri'],
        $fixture['verifier'],
        ['bot_mode' => 'create', 'bot_name' => 'Revocable Bot'],
    );

    mcpGetConnection($tokens['access_token']);

    test()->postJson('/oauth/revoke', [
        'token' => $tokens['refresh_token'],
        'token_type_hint' => 'refresh_token',
        'client_id' => $fixture['client_id'],
    ])->assertOk();

    test()->withToken($tokens['access_token'])->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2025-11-25',
            'clientInfo' => ['name' => 'Grok Bot Harness', 'version' => '0.0.1'],
        ],
    ])->assertUnauthorized();

    agentInitialize($hoku['token'])->assertOk();
});

test('viewer cannot approve a bot install and wrong-team bot link is rejected', function () {
    $fixture = botInstallOAuthFixture();
    $viewer = User::factory()->create();
    $fixture['team']->memberships()->create(['user_id' => $viewer->id, 'role' => TeamRole::Viewer]);

    test()->actingAs($viewer)->post('/oauth/authorize', [
        ...$fixture['parameters'],
        'decision' => 'approve',
        'team' => $fixture['team']->slug,
        'bot_mode' => 'create',
        'bot_name' => 'Viewer Bot',
    ])->assertSessionHasErrors('bot_mode');

    $otherTeam = Team::factory()->create(['plan' => Plan::Team]);
    $foreignBot = User::factory()->bot()->create(['name' => 'Foreign Bot']);
    $otherTeam->memberships()->create(['user_id' => $foreignBot->id, 'role' => TeamRole::Member]);

    test()->actingAs($fixture['admin'])->post('/oauth/authorize', [
        ...$fixture['parameters'],
        'decision' => 'approve',
        'team' => $fixture['team']->slug,
        'bot_mode' => 'link',
        'bot_id' => (string) $foreignBot->id,
    ])->assertSessionHasErrors('bot_id');
});

test('human oauth without bot_mode still reports human bearer identity', function () {
    $fixture = botInstallOAuthFixture();

    $tokens = completeBotInstallOAuth(
        $fixture['admin'],
        $fixture['team'],
        $fixture['parameters'],
        $fixture['client_id'],
        $fixture['redirect_uri'],
        $fixture['verifier'],
        [],
    );

    $connection = mcpGetConnection($tokens['access_token']);
    expect($connection['user_id'])->toBe((string) $fixture['admin']->id)
        ->and($connection['credential_source'])->toBe('bearer')
        ->and($connection['agent'])->toBeNull()
        ->and(OAuthBotInstallation::query()->count())->toBe(0);
});

test('bot oauth deploy attributes to the bot name not the consenting human', function () {
    $fixture = botInstallOAuthFixture();
    configureArtifactLinks();
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake([
        'worker.test/v1/orgs/*/usage' => Http::response(['storage_bytes' => 0, 'artifacts_this_period' => 0], 200),
        'worker.test/v1/artifacts' => Http::response([
            'id' => ARTIFACT_LINK_ID,
            'url' => 'https://worker.test/p/'.ARTIFACT_LINK_ID,
            'tier' => 'secure',
            'missing_files' => [],
        ], 201),
    ]);
    app()->bind(UsageContract::class, FakeUsage::class);

    $tokens = completeBotInstallOAuth(
        $fixture['admin'],
        $fixture['team'],
        $fixture['parameters'],
        $fixture['client_id'],
        $fixture['redirect_uri'],
        $fixture['verifier'],
        ['bot_mode' => 'create', 'bot_name' => 'Attrib Bot'],
    );

    test()->withToken($tokens['access_token'])
        ->withHeaders([
            'MCP-Client-Name' => 'Grok Bot Harness',
            'MCP-Client-Version' => '0.0.1',
        ])
        ->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'deploy_artifact',
                'arguments' => [
                    'html' => '<!doctype html><title>Bot install</title><p>ok</p>',
                ],
            ],
        ])
        ->assertOk();

    Http::assertSent(function ($request): bool {
        if ($request->method() !== 'POST' || ! str_ends_with((string) $request->url(), '/v1/artifacts')) {
            return false;
        }

        $provenance = $request['provenance'] ?? null;

        return is_array($provenance)
            && ($provenance['agent'] ?? null) === 'Attrib Bot'
            && ($provenance['sources']['agent'] ?? null) === 'credential';
    });
});

test('over-scope tool call is denied for a bot install missing deploy', function () {
    $fixture = botInstallOAuthFixture([
        McpScope::ArtifactsRead->value,
        McpScope::CollectionsRead->value,
        McpScope::UsageRead->value,
    ]);

    $tokens = completeBotInstallOAuth(
        $fixture['admin'],
        $fixture['team'],
        $fixture['parameters'],
        $fixture['client_id'],
        $fixture['redirect_uri'],
        $fixture['verifier'],
        ['bot_mode' => 'create', 'bot_name' => 'Read Only Bot'],
    );

    $initialize = test()->withToken($tokens['access_token'])->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2025-11-25',
            'clientInfo' => ['name' => 'Grok Bot Harness', 'version' => '0.0.1'],
        ],
    ])->assertOk();

    $response = test()->withToken($tokens['access_token'])
        ->withHeader('MCP-Session-Id', (string) $initialize->headers->get('MCP-Session-Id'))
        ->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => [
                'name' => 'deploy_artifact',
                'arguments' => [
                    'html' => '<!doctype html><title>nope</title>',
                ],
            ],
        ])
        ->assertOk();

    expect($response->json('result.content.0.text'))->toStartWith('insufficient_scope:');
});

test('dynamic registration accepts the documented cursor custom-scheme redirect uri', function () {
    $response = test()->postJson('/oauth/register', [
        'client_name' => 'Cursor Grok Bot',
        'redirect_uris' => [
            'cursor://anysphere.cursor-mcp/oauth/callback',
            'https://www.cursor.com/agents/mcp/oauth/callback',
            'http://localhost:8787/callback',
        ],
    ])->assertCreated();

    expect($response->json('redirect_uris'))->toContain('cursor://anysphere.cursor-mcp/oauth/callback')
        ->and(OAuthClient::query()->where('client_id', $response->json('client_id'))->exists())->toBeTrue();
});
