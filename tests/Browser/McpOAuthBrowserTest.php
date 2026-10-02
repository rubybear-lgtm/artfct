<?php

use App\Actions\Teams\CreateTeam;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('OAuth consent identifies the client user workspace and requested scopes', function () {
    $user = User::factory()->create(['name' => 'Consent Reviewer']);
    $team = app(CreateTeam::class)->handle($user, 'Consent Workspace');

    test()->actingAs($user);

    $parameters = http_build_query([
        'response_type' => 'code',
        'client_id' => 'artfct-cli',
        'redirect_uri' => 'http://127.0.0.1:43123/oauth/callback',
        'scope' => 'artifacts:read artifacts:delete usage:read',
        'state' => 'browser-state',
        'code_challenge' => str_repeat('c', 64),
        'code_challenge_method' => 'S256',
        'team' => $team->slug,
    ]);

    visit('/oauth/authorize?'.$parameters)
        ->assertSee('Connect artfct-cli')
        ->assertSee('Consent Reviewer')
        ->assertSee('Consent Workspace')
        ->assertSee('Read artifacts and search your workspace')
        ->assertSee('Delete artifacts from your workspace')
        ->assertSee('View usage and quota totals')
        ->assertNoJavaScriptErrors();
});

test('consent marks a destructive scope and leaves a read-only scope unmarked', function () {
    $user = User::factory()->create(['name' => 'Risk Reviewer']);
    $team = app(CreateTeam::class)->handle($user, 'Risk Workspace');

    test()->actingAs($user);

    // Both risk levels are on the page at once so the read-only row is
    // checked against a page that is genuinely rendering a warning somewhere
    // else -- a blanket "no warning anywhere" assertion would pass on a
    // consent screen that had lost the marking entirely.
    $parameters = http_build_query([
        'response_type' => 'code',
        'client_id' => 'artfct-cli',
        'redirect_uri' => 'http://127.0.0.1:43123/oauth/callback',
        'scope' => 'artifacts:read artifacts:delete',
        'state' => 'risk-state',
        'code_challenge' => str_repeat('c', 64),
        'code_challenge_method' => 'S256',
        'team' => $team->slug,
    ]);

    visit('/oauth/authorize?'.$parameters)
        ->assertCount('@scope-risk-read', 1)
        ->assertCount('@scope-risk-destructive', 1)
        ->assertSeeIn('@scope-risk-read', 'Read artifacts and search your workspace')
        ->assertDontSeeIn('@scope-risk-read', 'Destructive')
        ->assertDontSeeIn('@scope-risk-read', 'Can permanently delete artifacts.')
        ->assertSeeIn('@scope-risk-destructive', 'Delete artifacts from your workspace')
        ->assertSeeIn('@scope-risk-destructive', 'Destructive')
        ->assertSeeIn('@scope-risk-destructive', 'Can permanently delete artifacts.')
        ->assertNoJavaScriptErrors();
});

test('the consent form completes a CLI login by handing an authorization code to the loopback callback', function () {
    $user = User::factory()->create(['name' => 'CLI Approver']);
    $team = app(CreateTeam::class)->handle($user, 'CLI Workspace');

    test()->actingAs($user);

    // `artfct login --oauth` binds 127.0.0.1 on an ephemeral port and sends the
    // resulting loopback redirect URI with a PKCE S256 challenge. This suite
    // already serves the browser from a listening loopback address of its own,
    // so the callback is aimed there: same scheme, host and path shape the CLI
    // sends, and a port something actually answers on. A handoff that never
    // happens cannot be mistaken for one that did -- the browser is left on
    // chrome-error://chromewebdata/ when nothing is listening.
    $callback = url('/oauth/callback');
    $callbackPort = (string) parse_url($callback, PHP_URL_PORT);
    $verifier = str_repeat('v', 64);
    $state = 'cli-browser-state';

    $parameters = http_build_query([
        'response_type' => 'code',
        'client_id' => 'artfct-cli',
        'redirect_uri' => $callback,
        'scope' => 'artifacts:read artifacts:deploy collections:read collections:write usage:read',
        'state' => $state,
        'code_challenge' => oauthChallenge($verifier),
        'code_challenge_method' => 'S256',
        'team' => $team->slug,
    ]);

    $page = visit('/oauth/authorize?'.$parameters)
        ->assertSee('Connect artfct-cli')
        ->assertSee('CLI Workspace')
        ->assertSee('Read artifacts and search your workspace')
        ->assertNoJavaScriptErrors();

    // Everything above passes on a rendered consent screen alone, so the flow
    // is only proven once the form is submitted for real.
    $page->click('Allow access');

    $page->assertHostIs('127.0.0.1')
        ->assertPortIs($callbackPort)
        ->assertPathIs('/oauth/callback')
        ->assertQueryStringHas('state', $state)
        ->assertQueryStringHas('code');

    parse_str((string) parse_url($page->url(), PHP_URL_QUERY), $callbackQuery);

    // Receiving a code is half a login: it is only a real authorization code if
    // the verifier the CLI never put in a URL completes the PKCE exchange.
    configureSigning(testSigningKey());

    $this->postJson('/oauth/token', [
        'grant_type' => 'authorization_code',
        'code' => $callbackQuery['code'],
        'client_id' => 'artfct-cli',
        'redirect_uri' => $callback,
        'code_verifier' => $verifier,
    ])->assertOk()
        ->assertJsonPath('token_type', 'Bearer')
        ->assertJsonPath('organization', $team->slug)
        ->assertJsonPath('scope', 'artifacts:read artifacts:deploy collections:read collections:write usage:read')
        ->assertJsonStructure(['access_token', 'refresh_token']);
});
