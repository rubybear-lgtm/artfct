<?php

use App\Models\ExternalIdentity;
use App\Models\User;
use App\Services\AuthKit\AuthKitClientContract;
use App\Services\AuthKit\AuthKitProfile;
use App\Services\AuthKit\FakeAuthKitClient;
use App\Services\AuthKit\RealAuthKitClient;

/**
 * A stand-in for the real hosted client: it accepts any code, so these tests
 * isolate the callback's own `state` check.
 */
function bindHostedClient(): void
{
    app()->instance(AuthKitClientContract::class, new class implements AuthKitClientContract
    {
        public function authenticateWithCode(string $code): AuthKitProfile
        {
            return new AuthKitProfile('ext-1', 'GoogleOAuth', 'person@example.com', true, 'Person', null, null);
        }
    });
}

test('callback_rejects_a_missing_state', function () {
    bindHostedClient();

    test()->get(route('authenticate', ['code' => 'abc']))->assertForbidden();

    test()->assertGuest();
    expect(User::query()->where('email', 'person@example.com')->exists())->toBeFalse();
});

test('callback_rejects_a_state_that_does_not_match_the_session', function () {
    bindHostedClient();

    test()->withSession(['authkit_state' => 'expected-state'])
        ->get(route('authenticate', ['code' => 'abc', 'state' => 'attacker-state']))
        ->assertForbidden();

    test()->assertGuest();
});

test('callback_accepts_the_matching_state_once', function () {
    bindHostedClient();

    test()->withSession(['authkit_state' => 'good-state'])
        ->get(route('authenticate', ['code' => 'abc', 'state' => 'good-state']))
        ->assertRedirect();

    test()->assertAuthenticated();
    // The state is single use: replaying the same callback fails.
    auth()->logout();
    test()->get(route('authenticate', ['code' => 'abc', 'state' => 'good-state']))->assertForbidden();
});

test('sign_in_endpoints_are_rate_limited_per_ip', function () {
    config(['auth.throttle_per_minute' => 3]);
    $code = base64_encode('x');

    foreach (range(1, 3) as $attempt) {
        test()->get(route('authenticate', ['code' => $code]))->assertStatus(403);
    }

    test()->get(route('authenticate', ['code' => $code]))->assertStatus(429);
});

test('login_state_round_trips_to_the_callback_check', function () {
    config(['services.workos.client_id' => 'client_x', 'services.workos.secret' => 'sk_test_x', 'services.workos.redirect_url' => 'https://app.test/authenticate']);
    app()->instance(AuthKitClientContract::class, new RealAuthKitClient);

    $response = test()->get(route('login'));
    $location = $response->headers->get('X-Inertia-Location') ?? $response->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect(session('authkit_state'))->toBe($query['state']);
});

test('login_redirects_an_inertia_visit_to_workos', function () {
    config(['services.workos.client_id' => 'client_x', 'services.workos.secret' => 'sk_test_x', 'services.workos.redirect_url' => 'https://app.test/authenticate']);
    app()->instance(AuthKitClientContract::class, new RealAuthKitClient);

    // An in-app visit (the app shell's Log in link) arrives as an Inertia
    // request, the branch that answers 409 rather than a 302. The version is
    // read from a served page and echoed back the way a browser would: Inertia's
    // own version-changed guard answers 409 for the current URL, and with a
    // mismatched version it would mask the controller entirely.
    $version = test()->get(route('home'))->viewData('page')['version'];

    $response = test()->get(route('login'), [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => $version,
    ]);

    $response->assertStatus(409);
    expect($response->headers->get('X-Inertia-Location'))->toContain('user_management/authorize');
});

test('dev_login_is_hidden_when_the_flag_is_off', function () {
    config(['services.authkit.dev_login_enabled' => false]);

    test()->post(route('authkit.dev-login'), ['email' => 'a@example.com', 'provider' => 'GoogleOAuth'])->assertNotFound();
});

test('a_deactivated_user_is_refused_at_sign_in', function () {
    $user = User::factory()->create(['email' => 'gone@example.com', 'deactivated_at' => now()]);
    $profile = new AuthKitProfile(hash('sha256', 'GoogleOAuth|gone@example.com'), 'GoogleOAuth', 'gone@example.com', true, 'Gone', null, null);
    ExternalIdentity::create(['user_id' => $user->id, 'provider' => 'GoogleOAuth', 'external_id' => $profile->externalId, 'email' => $profile->email, 'verified_at' => now()]);

    test()->get(route('authenticate', ['code' => FakeAuthKitClient::codeFor($profile)]))
        ->assertForbidden();
    test()->assertGuest();
});

test('logout_also_ends_the_workos_session', function () {
    $user = User::factory()->create();

    $response = test()->actingAs($user)->withSession(['workos_session_id' => 'session_123'])
        ->post(route('logout'), [], ['X-Inertia' => 'true']);

    $response->assertStatus(409);
    expect($response->headers->get('X-Inertia-Location'))->toContain('user_management/sessions/logout')->toContain('session_123');
    test()->assertGuest();
});

test('logout_without_a_workos_session_returns_home', function () {
    test()->actingAs(User::factory()->create())->post(route('logout'))->assertRedirect(route('home'));
    test()->assertGuest();
});
