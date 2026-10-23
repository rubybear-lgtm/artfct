<?php

use App\Rules\PublicHttpsUrl;

/**
 * @param  (Closure(string): list<string>)|null  $resolveHost
 */
function publicHttpsUrlPasses(string $url, ?Closure $resolveHost = null): bool
{
    $failed = false;

    (new PublicHttpsUrl($resolveHost))->validate('metadata_url', $url, function () use (&$failed): void {
        $failed = true;
    });

    return ! $failed;
}

test('accepts a public hostname that resolves to public addresses', function () {
    expect(publicHttpsUrlPasses(
        'https://idp.example.com/metadata',
        fn (): array => ['93.184.216.34'],
    ))->toBeTrue();
});

test('rejects the cloud metadata address', function () {
    expect(publicHttpsUrlPasses('https://169.254.169.254/latest/meta-data/'))->toBeFalse();
});

test('rejects a private-range host', function () {
    expect(publicHttpsUrlPasses('https://10.0.0.5/saml/metadata'))->toBeFalse();
    expect(publicHttpsUrlPasses('https://192.168.1.1/m'))->toBeFalse();
    expect(publicHttpsUrlPasses('https://172.16.0.1/m'))->toBeFalse();
});

test('rejects loopback and localhost', function () {
    expect(publicHttpsUrlPasses('https://127.0.0.1/m'))->toBeFalse();
    expect(publicHttpsUrlPasses('https://[::1]/m'))->toBeFalse();
    expect(publicHttpsUrlPasses('https://localhost/m'))->toBeFalse();
});

test('rejects a hostname that resolves into a private range', function () {
    expect(publicHttpsUrlPasses(
        'https://idp.corp.example/metadata',
        fn (): array => ['10.1.2.3'],
    ))->toBeFalse();
});

test('rejects when DNS returns no addresses', function () {
    expect(publicHttpsUrlPasses(
        'https://missing.example.com/m',
        fn (): array => [],
    ))->toBeFalse();
});

test('rejects http, credentials, and blocked suffixes', function () {
    expect(publicHttpsUrlPasses('http://idp.example.com/m', fn (): array => ['1.2.3.4']))->toBeFalse();
    expect(publicHttpsUrlPasses('https://user:pass@idp.example.com/m', fn (): array => ['1.2.3.4']))->toBeFalse();
    expect(publicHttpsUrlPasses('https://vault.internal/m'))->toBeFalse();
});

test('accepts a public IP literal', function () {
    expect(publicHttpsUrlPasses('https://8.8.8.8/metadata'))->toBeTrue();
});
