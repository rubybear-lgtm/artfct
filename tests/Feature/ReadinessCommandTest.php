<?php

test('readiness_flags_a_half_configured_integration', function () {
    config(['services.workos.client_id' => 'client_x', 'services.workos.secret' => null, 'services.workos.redirect_url' => null]);

    test()->artisan('app:readiness')->expectsOutputToContain('incomplete')->assertFailed();
});

test('readiness_strict_fails_when_nothing_is_configured', function () {
    config(['services.workos' => [], 'services.stripe' => [], 'services.org_jwt' => [], 'mail.default' => 'log']);

    test()->artisan('app:readiness')->assertSuccessful();
    test()->artisan('app:readiness --strict')->assertFailed();
});

test('readiness_passes_when_every_integration_is_configured', function () {
    config([
        'services.workos' => ['client_id' => 'c', 'secret' => 's', 'redirect_url' => 'https://x.test/authenticate'],
        'services.stripe' => ['secret' => 'sk', 'webhook_secret' => 'wh', 'team_price_id' => 'price'],
        'services.org_jwt' => ['private_key' => 'key', 'kid' => 'kid', 'issuer' => 'https://artfct.dev', 'audience' => 'artfct-engine', 'worker_base_url' => 'https://worker.test', 'jwks_write_secret' => 'j', 'revocation_write_secret' => 'r'],
        'mail.default' => 'cloudflare',
        'services.cloudflare_email' => ['account_id' => 'a', 'api_token' => 't'],
    ]);

    test()->artisan('app:readiness --strict')->assertSuccessful();
});

test('readiness_reports_a_half_configured_worker_connection', function () {
    config(['services.org_jwt' => [
        'private_key' => 'key', 'kid' => 'kid', 'issuer' => 'https://artfct.dev', 'audience' => 'artfct-engine',
        'worker_base_url' => 'https://worker.test', 'revocation_write_secret' => 'r', 'jwks_write_secret' => null,
    ]]);

    test()->artisan('app:readiness')
        ->expectsOutputToContain('services.org_jwt.jwks_write_secret')
        ->assertFailed();
});
