<?php

use App\Services\AuthKit\AuthKitClientContract;
use App\Services\AuthKit\FakeAuthKitClient;

test('the dev login flag defaults to off when nothing enables it', function () {
    $services = withEnvironmentVariables(['AUTHKIT_DEV_LOGIN_ENABLED' => null], fn (): array => require base_path('config/services.php'));

    expect($services['authkit']['dev_login_enabled'])->toBeFalse();
});

test('dev login answers 404 when the flag is unset', function () {
    config(['services.authkit.dev_login_enabled' => false]);

    test()->post(route('authkit.dev-login'), ['email' => 'someone@example.com', 'provider' => 'GoogleOAuth'])
        ->assertNotFound();

    test()->assertGuest();
});

test('dev login answers 404 on a non loopback host even when the flag is on', function () {
    config(['services.authkit.dev_login_enabled' => true]);

    test()->post('https://artfct.example/authkit/dev-login', ['email' => 'someone@example.com', 'provider' => 'GoogleOAuth'])
        ->assertNotFound();

    test()->assertGuest();
});

test('dev login accepts a loopback or test host when the flag is on', function () {
    config(['services.authkit.dev_login_enabled' => true]);

    test()->post('http://127.0.0.1/authkit/dev-login', ['email' => 'someone@example.com', 'provider' => 'GoogleOAuth'])
        ->assertRedirect();
    test()->post('http://artfct.test/authkit/dev-login', ['email' => 'someone@example.com', 'provider' => 'GoogleOAuth'])
        ->assertRedirect();
});

test('the fake AuthKit client is never bound outside local and testing', function () {
    app()->forgetInstance(AuthKitClientContract::class);
    config(['services.workos.client_id' => null]);
    app()->detectEnvironment(fn (): string => 'staging');

    expect(fn () => app(AuthKitClientContract::class))->toThrow(RuntimeException::class, 'WorkOS is not fully configured');
});

test('the fake AuthKit client is bound in the local environment', function () {
    app()->forgetInstance(AuthKitClientContract::class);
    app()->detectEnvironment(fn (): string => 'local');

    expect(app(AuthKitClientContract::class))->toBeInstanceOf(FakeAuthKitClient::class);
});
