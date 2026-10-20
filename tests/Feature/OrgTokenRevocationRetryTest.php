<?php

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

function insertPendingOrgTokenRevocation(): void
{
    DB::table('org_token_revocation_retries')->insert([
        'jti_hash' => hash('sha256', 'jti-1'),
        'encrypted_jti' => Crypt::encryptString('jti-1'),
        'expires_at' => now()->addMinutes(5),
        'attempts' => 0,
        'retry_after' => now()->subMinute(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

test('retry_fails_visibly_when_the_revocation_secret_is_missing', function () {
    config(['services.org_jwt.worker_base_url' => 'https://worker.test', 'services.org_jwt.revocation_write_secret' => null]);
    insertPendingOrgTokenRevocation();
    Http::fake();

    test()->artisan('app:retry-org-token-revocations')
        ->expectsOutputToContain('ARTFCT_REVOCATION_WRITE_SECRET')
        ->assertFailed();

    Http::assertNothingSent();
    expect(DB::table('org_token_revocation_retries')->value('attempts'))->toBe(0);
});

test('retry_treats_a_worker_failure_as_transient_and_backs_off', function () {
    config(['services.org_jwt.worker_base_url' => 'https://worker.test', 'services.org_jwt.revocation_write_secret' => 'secret']);
    insertPendingOrgTokenRevocation();
    Http::fake(['worker.test/*' => Http::response('down', 503)]);

    test()->artisan('app:retry-org-token-revocations')->assertSuccessful();

    expect(DB::table('org_token_revocation_retries')->value('attempts'))->toBe(1);
});

test('retry_does_not_require_the_secret_when_nothing_is_pending', function () {
    config(['services.org_jwt.revocation_write_secret' => null]);

    test()->artisan('app:retry-org-token-revocations')->assertSuccessful();
});
