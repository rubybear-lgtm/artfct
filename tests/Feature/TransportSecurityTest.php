<?php

const STRICT_TRANSPORT_SECURITY = 'max-age=31536000; includeSubDomains';

/**
 * The session config is read the way `php artisan config:cache` reads it: by
 * requiring the file with the given environment variables in place.
 *
 * @param  array<string, string|null>  $variables
 * @return array<string, mixed>
 */
function sessionConfigWithEnvironment(array $variables): array
{
    return withEnvironmentVariables($variables, fn (): array => require base_path('config/session.php'));
}

test('an https response in production carries Strict-Transport-Security', function () {
    app()->detectEnvironment(fn (): string => 'production');

    test()->get('https://artfct.example/up')
        ->assertOk()
        ->assertHeader('Strict-Transport-Security', STRICT_TRANSPORT_SECURITY);
});

test('an http response in production carries no Strict-Transport-Security', function () {
    app()->detectEnvironment(fn (): string => 'production');

    test()->get('http://artfct.example/up')
        ->assertOk()
        ->assertHeaderMissing('Strict-Transport-Security');
});

test('an https response locally carries no Strict-Transport-Security', function () {
    test()->get('https://artfct.example/up')
        ->assertOk()
        ->assertHeaderMissing('Strict-Transport-Security');
});

test('the session cookie is secure by default for an https production deployment', function () {
    $session = sessionConfigWithEnvironment([
        'APP_ENV' => 'production',
        'APP_URL' => 'https://artfct.example',
        'SESSION_SECURE_COOKIE' => null,
    ]);

    expect($session['secure'])->toBeTrue();
});

test('the session cookie is secure by default outside local and testing even without an https url', function () {
    $session = sessionConfigWithEnvironment([
        'APP_ENV' => 'staging',
        'APP_URL' => 'http://artfct.example',
        'SESSION_SECURE_COOKIE' => null,
    ]);

    expect($session['secure'])->toBeTrue();
});

test('the session cookie is not secure by default for local http development', function () {
    $session = sessionConfigWithEnvironment([
        'APP_ENV' => 'local',
        'APP_URL' => 'http://localhost',
        'SESSION_SECURE_COOKIE' => null,
    ]);

    expect($session['secure'])->toBeFalse();
});

test('an explicit SESSION_SECURE_COOKIE overrides the default', function () {
    $session = sessionConfigWithEnvironment([
        'APP_ENV' => 'production',
        'APP_URL' => 'https://artfct.example',
        'SESSION_SECURE_COOKIE' => 'false',
    ]);

    expect($session['secure'])->toBeFalse();
});
