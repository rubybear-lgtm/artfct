<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('RUB-598: consent without a hint forces a workspace choice before Allow access binds the grant', function () {
    $user = User::factory()->create();
    $defaultTeam = Team::factory()->create(['name' => 'Default Workspace']);
    $pickedTeam = Team::factory()->create(['name' => 'Picked Workspace']);
    $defaultTeam->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::Admin]);
    $pickedTeam->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::Admin]);
    $user->switchTeam($defaultTeam);
    test()->actingAs($user);

    $callback = url('/oauth/callback');
    oauthTestClient([$callback]);
    $verifier = str_repeat('m', 64);
    $state = 'rub598-browser-state';

    // Production MCP clients send no team hint: the consent screen must not
    // preselect the stale session default.
    $parameters = http_build_query([
        'response_type' => 'code',
        'client_id' => 'test-native-client',
        'redirect_uri' => $callback,
        'scope' => 'artifacts:read artifacts:deploy',
        'state' => $state,
        'code_challenge' => oauthChallenge($verifier),
        'code_challenge_method' => 'S256',
    ]);

    $page = visit('/oauth/authorize?'.$parameters)
        ->assertSee('Test native client wants to connect')
        ->assertSee('Choose below')
        ->assertNoJavaScriptErrors();

    $page->select('team', $pickedTeam->slug);
    $page->click('Allow access');

    $page->assertQueryStringHas('state', $state)
        ->assertQueryStringHas('code');

    parse_str((string) parse_url($page->url(), PHP_URL_QUERY), $callbackQuery);

    configureSigning(testSigningKey());

    test()->postJson('/oauth/token', [
        'grant_type' => 'authorization_code',
        'code' => $callbackQuery['code'],
        'client_id' => 'test-native-client',
        'redirect_uri' => $callback,
        'code_verifier' => $verifier,
    ])->assertOk()->assertJsonPath('organization', $pickedTeam->slug);
});
