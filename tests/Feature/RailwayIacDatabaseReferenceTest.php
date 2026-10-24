<?php

use Illuminate\Support\Facades\File;

/**
 * Railway only deploys dependency databases in PR environments (and only waits
 * on them in a batch deploy) when app services use a cross-service reference
 * such as `${{Postgres-tsII.DATABASE_URL}}`. IaC must express that as
 * `db.env.DATABASE_URL`, not `preserve()`, or preview web/queue fail DNS
 * against an undeployed `postgres-*.railway.internal` host.
 */
it('wires Laravel and Polis DB_URL to typed Postgres references in Railway IaC', function () {
    $production = File::get(base_path('.railway/production.ts'));
    $staging = File::get(base_path('.railway/staging.ts'));

    expect($production)
        ->toContain('DB_URL: PostgresTsII.env.DATABASE_URL')
        ->not->toContain('DB_URL: preserve()');

    expect(substr_count($production, 'DB_URL: PostgresTsII.env.DATABASE_URL'))->toBe(3);

    expect($staging)
        ->toContain('DB_URL: Postgres.env.DATABASE_URL')
        ->toContain('DB_URL: PostgresXPCc.env.DATABASE_URL')
        ->not->toContain('DB_URL: preserve()');

    expect(substr_count($staging, 'DB_URL: Postgres.env.DATABASE_URL'))->toBe(3);
    expect(substr_count($staging, 'DB_URL: PostgresXPCc.env.DATABASE_URL'))->toBe(1);
});
