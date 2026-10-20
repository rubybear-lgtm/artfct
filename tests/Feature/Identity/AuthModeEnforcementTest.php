<?php

use App\Enums\AuthMode;
use App\Enums\TeamRole;
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
