<?php

use App\Services\AuthKit\AuthKitClientContract;
use App\Services\AuthKit\FakeAuthKitClient;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;

/*
 * Launch review: with incomplete WorkOS config the app used to bind a fake
 * sign-in client in every environment. Production must fail loudly instead.
 */
test('production never falls back to the fake AuthKit client', function () {
    app()->forgetInstance(AuthKitClientContract::class);
    app()->detectEnvironment(fn (): string => 'production');

    expect(fn () => app(AuthKitClientContract::class))->toThrow(RuntimeException::class, 'WorkOS is not fully configured');
});

test('the fake AuthKit client is still used outside production', function () {
    expect(app(AuthKitClientContract::class))->toBeInstanceOf(FakeAuthKitClient::class);
});

test('dev login answers 404 in production even if the flag is switched on', function () {
    config(['services.authkit.dev_login_enabled' => true]);
    app()->detectEnvironment(fn (): string => 'production');

    // The environment switch also turns CSRF enforcement on; the controller's own answer is what is under test.
    test()->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('authkit.dev-login'), ['email' => 'someone@example.com', 'provider' => 'GoogleOAuth'])
        ->assertNotFound();
});
