<?php

use App\Services\AuthKit\AuthKitClientContract;
use App\Services\AuthKit\FakeAuthKitClient;

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
