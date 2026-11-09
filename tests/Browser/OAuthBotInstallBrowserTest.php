<?php

use App\Actions\Teams\CreateTeam;
use App\Enums\TeamRole;
use App\Models\OAuthBotInstallation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('consent UI can create a bot teammate and the code exchanges as that bot', function () {
    $user = User::factory()->create(['name' => 'Bot Consent Admin']);
    $team = app(CreateTeam::class)->handle($user, 'Bot Install Workspace');

    test()->actingAs($user);

    $callback = url('/oauth/callback');
    oauthTestClient([$callback]);
    $callbackPort = (string) parse_url($callback, PHP_URL_PORT);
    $verifier = str_repeat('v', 64);
    $state = 'bot-install-browser-state';

    $parameters = http_build_query([
        'response_type' => 'code',
        'client_id' => 'test-native-client',
        'redirect_uri' => $callback,
        'scope' => 'artifacts:read artifacts:deploy collections:read collections:write usage:read',
        'state' => $state,
        'code_challenge' => oauthChallenge($verifier),
        'code_challenge_method' => 'S256',
        'team' => $team->slug,
    ]);

    $page = visit('/oauth/authorize?'.$parameters)
        ->assertSee('Test native client wants to connect to Bot Install Workspace')
        ->assertSee('Connect as')
        ->assertSee('A new bot teammate')
        ->assertPresent('@bot-mode-create')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'rub524-consent-human-default');

    $page->click('@bot-mode-create')
        ->assertPresent('@bot-name-input')
        ->fill('@bot-name-input', 'Browser Grok Bot')
        ->screenshot(filename: 'rub524-consent-create-bot')
        ->click('Allow access');

    $page->assertHostIs('127.0.0.1')
        ->assertPortIs($callbackPort)
        ->assertPathIs('/oauth/callback')
        ->assertQueryStringHas('state', $state)
        ->assertQueryStringHas('code');

    parse_str((string) parse_url($page->url(), PHP_URL_QUERY), $callbackQuery);

    configureSigning(testSigningKey());
    configureOrgJwt();

    $token = $this->postJson('/oauth/token', [
        'grant_type' => 'authorization_code',
        'code' => $callbackQuery['code'],
        'client_id' => 'test-native-client',
        'redirect_uri' => $callback,
        'code_verifier' => $verifier,
    ])->assertOk();

    $accessToken = $token->json('access_token');
    $bot = User::query()->where('name', 'Browser Grok Bot')->where('is_bot', true)->sole();

    expect(OAuthBotInstallation::query()->where('bot_user_id', $bot->id)->exists())->toBeTrue()
        ->and($bot->teamRole($team))->toBe(TeamRole::Member);

    $initialize = $this->withToken($accessToken)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2025-11-25',
            'clientInfo' => ['name' => 'browser-harness', 'version' => '1.0.0'],
        ],
    ])->assertOk();

    $connection = $this->withToken($accessToken)
        ->withHeader('MCP-Session-Id', (string) $initialize->headers->get('MCP-Session-Id'))
        ->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => ['name' => 'get_connection', 'arguments' => []],
        ])->assertOk()
        ->json('result.structuredContent');

    expect($connection['user_id'])->toBe((string) $bot->id)
        ->and($connection['credential_source'])->toBe('agent_token')
        ->and($connection['agent'])->toBe(['name' => 'Browser Grok Bot', 'bot' => true]);
});

test('cancel on bot create consent creates no bot teammate', function () {
    $user = User::factory()->create(['name' => 'Cancel Admin']);
    $team = app(CreateTeam::class)->handle($user, 'Cancel Workspace');

    test()->actingAs($user);

    $callback = url('/oauth/callback');
    oauthTestClient([$callback]);
    $verifier = str_repeat('v', 64);

    $parameters = http_build_query([
        'response_type' => 'code',
        'client_id' => 'test-native-client',
        'redirect_uri' => $callback,
        'scope' => 'artifacts:read usage:read',
        'state' => 'cancel-bot-state',
        'code_challenge' => oauthChallenge($verifier),
        'code_challenge_method' => 'S256',
        'team' => $team->slug,
    ]);

    visit('/oauth/authorize?'.$parameters)
        ->assertPresent('@bot-mode-create')
        ->click('@bot-mode-create')
        ->fill('@bot-name-input', 'Orphan Candidate')
        ->click('Cancel')
        ->assertQueryStringHas('error', 'access_denied');

    expect(User::query()->where('name', 'Orphan Candidate')->exists())->toBeFalse()
        ->and(OAuthBotInstallation::query()->count())->toBe(0);
});
