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
], string $clientName = 'Grok Bot Harness'): array
{
    configureSigning(testSigningKey());
    configureOrgJwt();

    $admin = User::factory()->create(['name' => 'Consent Admin']);
    $team = Team::factory()->create(['plan' => Plan::Team, 'slug' => 'bot-install-team']);
    $team->memberships()->create(['user_id' => $admin->id, 'role' => TeamRole::Admin]);
    $admin->forceFill(['current_team_id' => $team->id])->save();

    $redirectUri = 'https://client.example.test/oauth/callback';
    $registration = test()->postJson('/oauth/register', [
        'client_name' => $clientName,
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
            ->has('bots', 0)
            ->has('connectedBots', 0)
            ->where('lastUsedBotId', null)
            ->where('preselectedBotMode', 'human')
            ->where('preselectedBotId', null));
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

test('AC1 create librarian on shared cursor client returns librarian from get_connection', function () {
    $fixture = botInstallOAuthFixture();

    completeBotInstallOAuth(
        $fixture['admin'],
        $fixture['team'],
        $fixture['parameters'],
        $fixture['client_id'],
        $fixture['redirect_uri'],
        $fixture['verifier'],
        ['bot_mode' => 'create', 'bot_name' => 'Hoku'],
    );
    $hoku = User::query()->where('name', 'Hoku')->where('is_bot', true)->sole();

    $verifier2 = str_repeat('c', 64);
    $parameters2 = oauthParameters(oauthChallenge($verifier2), [
        'client_id' => $fixture['client_id'],
        'redirect_uri' => $fixture['redirect_uri'],
        'scope' => $fixture['parameters']['scope'],
    ]);
    $librarianTokens = completeBotInstallOAuth(
        $fixture['admin'],
        $fixture['team'],
        $parameters2,
        $fixture['client_id'],
        $fixture['redirect_uri'],
        $verifier2,
        ['bot_mode' => 'create', 'bot_name' => 'Librarian'],
    );

    $librarian = User::query()->where('name', 'Librarian')->where('is_bot', true)->sole();
    expect($librarian->id)->not->toBe($hoku->id)
        ->and(OAuthBotInstallation::query()->where('team_id', $fixture['team']->id)->where('oauth_client_id', $fixture['client_id'])->count())->toBe(2);

    $connection = mcpGetConnection($librarianTokens['access_token']);
    expect($connection['user_id'])->toBe((string) $librarian->id)
        ->and($connection['agent'])->toBe(['name' => 'Librarian', 'bot' => true]);
});

test('AC2 installing librarian does not retarget or revoke hoku binding or tokens', function () {
    $fixture = botInstallOAuthFixture();

    $hokuTokens = completeBotInstallOAuth(
        $fixture['admin'],
        $fixture['team'],
        $fixture['parameters'],
        $fixture['client_id'],
        $fixture['redirect_uri'],
        $fixture['verifier'],
        ['bot_mode' => 'create', 'bot_name' => 'Hoku'],
    );
    $hoku = User::query()->where('name', 'Hoku')->where('is_bot', true)->sole();
    $hokuInstallationId = OAuthBotInstallation::query()
        ->where('team_id', $fixture['team']->id)
        ->where('oauth_client_id', $fixture['client_id'])
        ->where('bot_user_id', $hoku->id)
        ->sole()
        ->id;
    $hokuOrgToken = OrgToken::query()->where('user_id', $hoku->id)->where('kind', OrgTokenKind::Agent)->latest('id')->firstOrFail();

    $verifier2 = str_repeat('d', 64);
    $parameters2 = oauthParameters(oauthChallenge($verifier2), [
        'client_id' => $fixture['client_id'],
        'redirect_uri' => $fixture['redirect_uri'],
        'scope' => $fixture['parameters']['scope'],
    ]);
    completeBotInstallOAuth(
        $fixture['admin'],
        $fixture['team'],
        $parameters2,
        $fixture['client_id'],
        $fixture['redirect_uri'],
        $verifier2,
        ['bot_mode' => 'create', 'bot_name' => 'Librarian'],
    );

    $hokuInstallation = OAuthBotInstallation::query()->findOrFail($hokuInstallationId);
    expect($hokuInstallation->bot_user_id)->toBe($hoku->id)
        ->and($hokuOrgToken->fresh()->revoked_at)->toBeNull();

    $connection = mcpGetConnection($hokuTokens['access_token']);
    expect($connection['user_id'])->toBe((string) $hoku->id)
        ->and($connection['agent'])->toBe(['name' => 'Hoku', 'bot' => true]);
});

test('AC3 existing bot teammate pick still works on the shared client', function () {
    $fixture = botInstallOAuthFixture();

    completeBotInstallOAuth(
        $fixture['admin'],
        $fixture['team'],
        $fixture['parameters'],
        $fixture['client_id'],
        $fixture['redirect_uri'],
        $fixture['verifier'],
        ['bot_mode' => 'create', 'bot_name' => 'Hoku'],
    );

    $librarian = User::factory()->bot()->create(['name' => 'Librarian']);
    $fixture['team']->memberships()->create(['user_id' => $librarian->id, 'role' => TeamRole::Member]);

    $verifier2 = str_repeat('e', 64);
    $parameters2 = oauthParameters(oauthChallenge($verifier2), [
        'client_id' => $fixture['client_id'],
        'redirect_uri' => $fixture['redirect_uri'],
        'scope' => $fixture['parameters']['scope'],
    ]);
    $tokens = completeBotInstallOAuth(
        $fixture['admin'],
        $fixture['team'],
        $parameters2,
        $fixture['client_id'],
        $fixture['redirect_uri'],
        $verifier2,
        ['bot_mode' => 'link', 'bot_id' => (string) $librarian->id],
    );

    expect(OAuthBotInstallation::query()->where('team_id', $fixture['team']->id)->where('oauth_client_id', $fixture['client_id'])->count())->toBe(2);

    $connection = mcpGetConnection($tokens['access_token']);
    expect($connection['user_id'])->toBe((string) $librarian->id)
        ->and($connection['agent'])->toBe(['name' => 'Librarian', 'bot' => true]);
});

test('AC4 reconnect create is idempotent and same name reuses existing bot', function () {
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
        ['bot_mode' => 'create', 'bot_name' => 'Reconnect Bot'],
    );

    expect(User::query()->where('name', 'Reconnect Bot')->where('is_bot', true)->count())->toBe(1)
        ->and(OAuthBotInstallation::query()->where('team_id', $fixture['team']->id)->where('oauth_client_id', $fixture['client_id'])->where('bot_user_id', $bot->id)->count())->toBe(1)
        ->and(User::query()->find($hokuBotId)?->name)->toBe('Hoku Seed')
        ->and(OrgToken::query()->find($hoku['id'])?->revoked_at)->toBeNull();

    $connection = mcpGetConnection($second['access_token']);
    expect($connection['user_id'])->toBe((string) $bot->id)
        ->and($connection['agent']['name'])->toBe('Reconnect Bot');

    expect($first['access_token'])->not->toBe($second['access_token']);

    agentInitialize($hoku['token'])->assertOk();
});

test('AC4 two MausBot bots on one OAuth client keep separate identities and tokens', function () {
    $fixture = botInstallOAuthFixture(clientName: 'MausBot');
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

    $first = completeBotInstallOAuth(
        $fixture['admin'],
        $fixture['team'],
        $fixture['parameters'],
        $fixture['client_id'],
        $fixture['redirect_uri'],
        $fixture['verifier'],
        ['bot_mode' => 'create', 'bot_name' => 'MausBot Alpha'],
    );
    $alpha = User::query()->where('name', 'MausBot Alpha')->where('is_bot', true)->sole();

    test()->withToken($first['access_token'])
        ->withHeaders([
            'MCP-Client-Name' => 'MausBot',
            'MCP-Client-Version' => '0.0.1',
        ])
        ->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'deploy_artifact',
                'arguments' => [
                    'html' => '<!doctype html><title>MausBot owned</title><p>ok</p>',
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
            && ($provenance['agent'] ?? null) === 'MausBot Alpha'
            && ($provenance['sources']['agent'] ?? null) === 'credential';
    });

    $reconnectVerifier = str_repeat('f', 64);
    $reconnectParameters = oauthParameters(oauthChallenge($reconnectVerifier), [
        'client_id' => $fixture['client_id'],
        'redirect_uri' => $fixture['redirect_uri'],
        'scope' => $fixture['parameters']['scope'],
    ]);
    $reconnected = completeBotInstallOAuth(
        $fixture['admin'],
        $fixture['team'],
        $reconnectParameters,
        $fixture['client_id'],
        $fixture['redirect_uri'],
        $reconnectVerifier,
        ['bot_mode' => 'create', 'bot_name' => 'MausBot Alpha'],
    );

    expect(User::query()->where('name', 'MausBot Alpha')->where('is_bot', true)->count())->toBe(1)
        ->and(OAuthBotInstallation::query()
            ->where('team_id', $fixture['team']->id)
            ->where('oauth_client_id', $fixture['client_id'])
            ->where('bot_user_id', $alpha->id)
            ->count())->toBe(1)
        ->and($reconnected['access_token'])->not->toBe($first['access_token']);

    $secondVerifier = str_repeat('a', 64);
    $secondParameters = oauthParameters(oauthChallenge($secondVerifier), [
        'client_id' => $fixture['client_id'],
        'redirect_uri' => $fixture['redirect_uri'],
        'scope' => $fixture['parameters']['scope'],
    ]);
    $second = completeBotInstallOAuth(
        $fixture['admin'],
        $fixture['team'],
        $secondParameters,
        $fixture['client_id'],
        $fixture['redirect_uri'],
        $secondVerifier,
        ['bot_mode' => 'create', 'bot_name' => 'MausBot Beta'],
    );
    $beta = User::query()->where('name', 'MausBot Beta')->where('is_bot', true)->sole();

    expect($beta->id)->not->toBe($alpha->id)
        ->and($second['access_token'])->not->toBe($first['access_token'])
        ->and($second['access_token'])->not->toBe($reconnected['access_token'])
        ->and(OAuthBotInstallation::query()
            ->where('team_id', $fixture['team']->id)
            ->where('oauth_client_id', $fixture['client_id'])
            ->count())->toBe(2);

    $alphaConnection = mcpGetConnection($reconnected['access_token']);
    $betaConnection = mcpGetConnection($second['access_token']);

    expect($alphaConnection['user_id'])->toBe((string) $alpha->id)
        ->and($alphaConnection['agent'])->toBe(['name' => 'MausBot Alpha', 'bot' => true])
        ->and($betaConnection['user_id'])->toBe((string) $beta->id)
        ->and($betaConnection['agent'])->toBe(['name' => 'MausBot Beta', 'bot' => true])
        ->and($betaConnection['user_id'])->not->toBe($alphaConnection['user_id']);
});

test('AC6 revoke then reconnect with a different name does not resurrect the old bot', function () {
    $fixture = botInstallOAuthFixture();
    Http::fake([
        'https://worker.test/v1/internal/revocations' => Http::response(['revoked' => true], 200),
    ]);
    config(['services.worker.base_url' => 'https://worker.test']);

    $first = completeBotInstallOAuth(
        $fixture['admin'],
        $fixture['team'],
        $fixture['parameters'],
        $fixture['client_id'],
        $fixture['redirect_uri'],
        $fixture['verifier'],
        ['bot_mode' => 'create', 'bot_name' => 'Hoku'],
    );
    $hoku = User::query()->where('name', 'Hoku')->where('is_bot', true)->sole();

    test()->postJson('/oauth/revoke', [
        'token' => $first['refresh_token'],
        'token_type_hint' => 'refresh_token',
        'client_id' => $fixture['client_id'],
    ])->assertOk();

    $verifier2 = str_repeat('f', 64);
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
        ['bot_mode' => 'create', 'bot_name' => 'Librarian'],
    );

    $librarian = User::query()->where('name', 'Librarian')->where('is_bot', true)->sole();
    expect($librarian->id)->not->toBe($hoku->id)
        ->and(User::query()->where('name', 'Hoku')->where('is_bot', true)->count())->toBe(1);

    $connection = mcpGetConnection($second['access_token']);
    expect($connection['user_id'])->toBe((string) $librarian->id)
        ->and($connection['agent'])->toBe(['name' => 'Librarian', 'bot' => true])
        ->and($connection['user_id'])->not->toBe((string) $hoku->id)
        ->and(OAuthBotInstallation::query()->where('team_id', $fixture['team']->id)->where('oauth_client_id', $fixture['client_id'])->count())->toBe(2)
        ->and(OAuthBotInstallation::query()->where('team_id', $fixture['team']->id)->where('oauth_client_id', $fixture['client_id'])->where('bot_user_id', $hoku->id)->exists())->toBeTrue()
        ->and(OAuthBotInstallation::query()->where('team_id', $fixture['team']->id)->where('oauth_client_id', $fixture['client_id'])->where('bot_user_id', $librarian->id)->exists())->toBeTrue();
});

test('A2 exactly one connected bot silently reuses it via preselected link', function () {
    $fixture = botInstallOAuthFixture();

    completeBotInstallOAuth(
        $fixture['admin'],
        $fixture['team'],
        $fixture['parameters'],
        $fixture['client_id'],
        $fixture['redirect_uri'],
        $fixture['verifier'],
        ['bot_mode' => 'create', 'bot_name' => 'Hoku'],
    );
    $hoku = User::query()->where('name', 'Hoku')->where('is_bot', true)->sole();

    $verifier2 = str_repeat('i', 64);
    $parameters2 = oauthParameters(oauthChallenge($verifier2), [
        'client_id' => $fixture['client_id'],
        'redirect_uri' => $fixture['redirect_uri'],
        'scope' => $fixture['parameters']['scope'],
    ]);

    // Consent asks nothing about which of many identities: link + Hoku is
    // preselected. The page still renders (no auto-approve).
    $consent = test()->actingAs($fixture['admin'])
        ->get('/oauth/authorize?'.http_build_query(array_diff_key($parameters2, array_flip(['consent_token']))))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('oauth/authorize')
            ->has('connectedBots', 1)
            ->where('connectedBots.0.id', $hoku->id)
            ->where('connectedBots.0.name', 'Hoku')
            ->where('lastUsedBotId', $hoku->id)
            ->where('preselectedBotMode', 'link')
            ->where('preselectedBotId', $hoku->id));

    expect($consent->headers->get('Location'))->toBeNull();

    // Approving with the silent-reuse default (link + that bot) reuses Hoku.
    $tokens = completeBotInstallOAuth(
        $fixture['admin'],
        $fixture['team'],
        $parameters2,
        $fixture['client_id'],
        $fixture['redirect_uri'],
        $verifier2,
        ['bot_mode' => 'link', 'bot_id' => (string) $hoku->id],
    );

    expect(User::query()->where('name', 'Hoku')->where('is_bot', true)->count())->toBe(1)
        ->and(OAuthBotInstallation::query()->where('team_id', $fixture['team']->id)->where('oauth_client_id', $fixture['client_id'])->count())->toBe(1);

    $connection = mcpGetConnection($tokens['access_token']);
    expect($connection['user_id'])->toBe((string) $hoku->id)
        ->and($connection['agent'])->toBe(['name' => 'Hoku', 'bot' => true]);
});

test('A2 multiple connected bots preselect last used without auto-approving', function () {
    $fixture = botInstallOAuthFixture();

    completeBotInstallOAuth(
        $fixture['admin'],
        $fixture['team'],
        $fixture['parameters'],
        $fixture['client_id'],
        $fixture['redirect_uri'],
        $fixture['verifier'],
        ['bot_mode' => 'create', 'bot_name' => 'Hoku'],
    );
    $hoku = User::query()->where('name', 'Hoku')->where('is_bot', true)->sole();

    $verifier2 = str_repeat('g', 64);
    $parameters2 = oauthParameters(oauthChallenge($verifier2), [
        'client_id' => $fixture['client_id'],
        'redirect_uri' => $fixture['redirect_uri'],
        'scope' => $fixture['parameters']['scope'],
    ]);
    completeBotInstallOAuth(
        $fixture['admin'],
        $fixture['team'],
        $parameters2,
        $fixture['client_id'],
        $fixture['redirect_uri'],
        $verifier2,
        ['bot_mode' => 'create', 'bot_name' => 'Librarian'],
    );
    $librarian = User::query()->where('name', 'Librarian')->where('is_bot', true)->sole();

    $verifier3 = str_repeat('h', 64);
    $parameters3 = oauthParameters(oauthChallenge($verifier3), [
        'client_id' => $fixture['client_id'],
        'redirect_uri' => $fixture['redirect_uri'],
        'scope' => $fixture['parameters']['scope'],
    ]);

    // More than one → always ask. Last-used (Librarian) is preselected as link,
    // but GET still returns the consent page (never auto-approved).
    $consent = test()->actingAs($fixture['admin'])
        ->get('/oauth/authorize?'.http_build_query(array_diff_key($parameters3, array_flip(['consent_token']))))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('oauth/authorize')
            ->has('connectedBots', 2)
            ->where('connectedBots.0.id', $librarian->id)
            ->where('connectedBots.0.name', 'Librarian')
            ->where('connectedBots.1.id', $hoku->id)
            ->where('connectedBots.1.name', 'Hoku')
            ->where('lastUsedBotId', $librarian->id)
            ->where('preselectedBotMode', 'link')
            ->where('preselectedBotId', $librarian->id));

    expect($consent->headers->get('Location'))->toBeNull()
        ->and($consent->status())->toBe(200);
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
