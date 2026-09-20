<?php

use App\Models\User;
use App\Services\AuthKit\AuthKitClientContract;
use App\Services\AuthKit\AuthKitProfile;

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
