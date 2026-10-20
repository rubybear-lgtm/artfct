<?php

use App\Enums\AuthMode;
use App\Enums\Plan;
use App\Enums\TeamRole;
use App\Models\ExternalIdentity;
use App\Models\Team;
use App\Models\User;
use App\Services\AuthKit\AuthKitProfile;
use App\Services\AuthKit\FakeAuthKitClient;
use App\Services\Polis\FakePolisClient;
use App\Support\TeamSignIn;

/**
 * @return array{0: Team, 1: User}
 */
function enforcedTeam(AuthMode $authMode): array
{
    $team = Team::factory()->create(['slug' => 'acme', 'auth_mode' => $authMode]);
    $user = User::factory()->create(['email' => 'ada@acme.com']);
    $team->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::Member]);

    return [$team, $user];
}

function authKitSignInCode(User $user): string
{
    return FakeAuthKitClient::codeFor(new AuthKitProfile(
        externalId: 'workos-'.$user->id,
        provider: 'GoogleOAuth',
        email: $user->email,
        emailVerified: true,
        firstName: 'Ada',
        lastName: null,
        avatar: null,
    ));
}

function signInThroughAuthKit(User $user): void
{
    test()->get(route('authenticate', ['code' => authKitSignInCode($user)]))->assertRedirect();
    test()->assertAuthenticatedAs($user);
}

function signInThroughPolis(User $user): void
{
    $profile = new AuthKitProfile('ext-1', 'polis', $user->email, true, 'Ada', null, null);
    $code = FakePolisClient::codeFor($profile, 'acme', 'artfct');

    test()->withSession(['polis_state' => 'good'])
        ->get(route('sso.authenticate', ['team' => 'acme', 'code' => $code, 'state' => 'good']))
        ->assertRedirect();
    test()->assertAuthenticatedAs($user);
}

test('an authkit sign_in does not grant access to a polis team', function () {
    [$team, $user] = enforcedTeam(AuthMode::Polis);

    signInThroughAuthKit($user);

    test()->get(route('dashboard', $team))->assertForbidden();
    test()->get(route('console.index', $team))->assertForbidden();
});

test('an authkit sign_in cannot open the oauth consent for a polis team', function () {
    [$team, $user] = enforcedTeam(AuthMode::Polis);

    signInThroughAuthKit($user);

    test()->get('/oauth/authorize?'.http_build_query(oauthParameters(oauthChallenge('verifier'), ['team' => $team->slug])))
        ->assertForbidden();
});

test('a polis sign_in grants access to a polis team', function () {
    [$team, $user] = enforcedTeam(AuthMode::Polis);

    signInThroughPolis($user);

    test()->get(route('dashboard', $team))->assertOk();
});

test('a polis sign_in proves access only for the team and user it was made for', function () {
    [$team, $user] = enforcedTeam(AuthMode::Polis);
    $otherTeam = Team::factory()->create(['slug' => 'globex', 'auth_mode' => AuthMode::Polis]);
    $otherTeam->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::Member]);

    signInThroughPolis($user);
    test()->get(route('dashboard', $otherTeam))->assertForbidden();

    $stranger = User::factory()->create();
    $team->memberships()->create(['user_id' => $stranger->id, 'role' => TeamRole::Member]);
    test()->actingAs($stranger)->withSession([TeamSignIn::SESSION_KEY => [$team->id => $user->id]])
        ->get(route('dashboard', $team))
        ->assertForbidden();
});

test('an authkit sign_in clears a polis sign_in made earlier in the session', function () {
    [$team, $user] = enforcedTeam(AuthMode::Polis);

    signInThroughPolis($user);
    test()->get(route('dashboard', $team))->assertOk();

    // Log out without invalidating the session, so only the AuthKit callback can clear the marker.
    auth('web')->logout();
    signInThroughAuthKit($user);

    test()->get(route('dashboard', $team))->assertForbidden();
});

test('a dual team admits an authkit sign_in', function () {
    [$team, $user] = enforcedTeam(AuthMode::Dual);

    signInThroughAuthKit($user);

    test()->get(route('dashboard', $team))->assertOk();
});

test('a dual team admits a polis sign_in', function () {
    [$team, $user] = enforcedTeam(AuthMode::Dual);

    signInThroughPolis($user);

    test()->get(route('dashboard', $team))->assertOk();
});

test('an authkit team admits an authkit sign_in', function () {
    [$team, $user] = enforcedTeam(AuthMode::AuthKit);

    signInThroughAuthKit($user);

    test()->get(route('dashboard', $team))->assertOk();
});

test('a personal team is not restricted by sign_in method', function () {
    $team = Team::factory()->create(['slug' => 'ada-personal', 'is_personal' => true, 'auth_mode' => AuthMode::Polis]);
    $user = User::factory()->create(['email' => 'ada@acme.com']);
    $team->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::Admin]);

    signInThroughAuthKit($user);

    test()->get(route('dashboard', $team))->assertOk();
});

/**
 * An Enterprise team with a verified domain and an admin who holds a verified
 * Polis identity, so the transitioner allows the switch to SSO-only.
 *
 * @return array{0: Team, 1: User}
 */
function switchableTeam(): array
{
    $team = Team::factory()->create(['slug' => 'acme', 'auth_mode' => AuthMode::AuthKit, 'plan' => Plan::Enterprise]);
    $team->domains()->create(['domain' => 'acme.com', 'verified_at' => now()]);
    $admin = User::factory()->create(['email' => 'ada@acme.com']);
    $team->memberships()->create(['user_id' => $admin->id, 'role' => TeamRole::Admin]);
    ExternalIdentity::factory()->for($admin)->polis()->create(['verified_at' => now()]);

    return [$team, $admin];
}

test('an authkit admin cannot switch the team to polis and lock themselves out', function () {
    [$team, $admin] = switchableTeam();

    signInThroughAuthKit($admin);

    test()->patch(route('teams.auth-mode.update', $team), ['auth_mode' => 'polis'])
        ->assertSessionHasErrors('auth_mode');

    expect($team->fresh()->auth_mode)->toBe(AuthMode::AuthKit);
    test()->get(route('teams.edit', $team))->assertOk();
});

test('a polis signed admin can switch the team to polis and still reach settings', function () {
    [$team, $admin] = switchableTeam();

    signInThroughAuthKit($admin);
    test()->withSession([TeamSignIn::SESSION_KEY => [$team->id => $admin->id]])
        ->patch(route('teams.auth-mode.update', $team), ['auth_mode' => 'polis', 'confirmed' => true])
        ->assertRedirect();

    expect($team->fresh()->auth_mode)->toBe(AuthMode::Polis);
    test()->get(route('teams.edit', $team))->assertOk();
    test()->get(route('teams.authentication.show', $team))->assertOk();
});

test('an authkit sign_in cannot approve the oauth consent for a polis team', function () {
    [$team, $user] = enforcedTeam(AuthMode::Polis);

    signInThroughAuthKit($user);

    $parameters = oauthParameters(oauthChallenge('verifier'), ['team' => $team->slug]);
    test()->post('/oauth/authorize', array_merge($parameters, ['decision' => 'approve']))
        ->assertForbidden();
});
