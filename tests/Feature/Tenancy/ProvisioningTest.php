<?php

use App\Models\Team;
use App\Services\Tenancy\FakeTenantProvisioner;
use App\Services\Tenancy\TenantFleetMigrator;
use App\Services\Tenancy\TenantProvisioningService;

test('provision_creates_all_resources', function () {
    $team = Team::factory()->create(['slug' => 'acme']);
    $provisioner = app(FakeTenantProvisioner::class);
    $service = new TenantProvisioningService($provisioner);

    $service->provision($team, 'v1');

    expect($provisioner->methodsCalledFor('acme'))->toBe([
        'createDatabase',
        'createStoragePrefix',
        'uploadScript',
        'registerHostname',
    ]);
    expect($team->fresh()->provisioned_at)->not->toBeNull();
    expect($team->fresh()->provisioning_failed_step)->toBeNull();
    expect($team->fresh()->release_version)->toBe('v1');
});

test('provision_is_idempotent', function () {
    $team = Team::factory()->create(['slug' => 'acme']);
    $provisioner = app(FakeTenantProvisioner::class);
    $service = new TenantProvisioningService($provisioner);

    $service->provision($team, 'v1');
    $callCountAfterFirstRun = count($provisioner->calls);

    $service->provision($team->fresh(), 'v1');

    expect(count($provisioner->calls))->toBe($callCountAfterFirstRun, 'a second provision() must not call the provisioner again');
});

test('provision_failure_records_step_and_is_resumable', function () {
    $team = Team::factory()->create(['slug' => 'acme']);
    $provisioner = app(FakeTenantProvisioner::class);
    $service = new TenantProvisioningService($provisioner);

    // Simulate the command being killed midway: uploadScript fails.
    $provisioner->failNextCallFor('acme', 'uploadScript');

    expect(fn () => $service->provision($team, 'v1'))->toThrow(RuntimeException::class);

    $team = $team->fresh();
    expect($team->provisioned_at)->toBeNull();
    expect($team->provisioning_failed_step)->toBe('upload_script');
    expect($provisioner->methodsCalledFor('acme'))->toBe(['createDatabase', 'createStoragePrefix', 'uploadScript']);

    // Re-running resumes from the failed step -- createDatabase and
    // createStoragePrefix must NOT be called again.
    $service->provision($team, 'v1');

    expect($provisioner->methodsCalledFor('acme'))->toBe([
        'createDatabase',
        'createStoragePrefix',
        'uploadScript',
        'uploadScript',
        'registerHostname',
    ]);
    expect($team->fresh()->provisioned_at)->not->toBeNull();
    expect($team->fresh()->provisioning_failed_step)->toBeNull();
});

test('deprovision_removes_script_retains_data', function () {
    $team = Team::factory()->create(['slug' => 'acme']);
    $provisioner = app(FakeTenantProvisioner::class);
    $service = new TenantProvisioningService($provisioner);
    $service->provision($team, 'v1');
    $callsBeforeDeprovision = count($provisioner->calls);

    $service->deprovision($team->fresh());

    $callsDuringDeprovision = array_slice($provisioner->calls, $callsBeforeDeprovision);
    expect(array_column($callsDuringDeprovision, 'method'))->toBe(['removeScript'], 'deprovision must call removeScript only -- no D1/R2 deletion method exists on the contract, so data retention is structural');
    expect($team->fresh()->provisioned_at)->toBeNull();
});

test('migrate_all_brings_fleet_to_head', function () {
    Team::factory()->count(10)->create(['provisioned_at' => now()]);
    $provisioner = app(FakeTenantProvisioner::class);
    $migrator = new TenantFleetMigrator($provisioner);

    $report = $migrator->migrateAll(1);

    expect($report->succeeded)->toHaveCount(10);
    expect($report->failed)->toBe([]);
    expect(Team::query()->where('schema_version', 1)->count())->toBe(10);
});

test('migrate_all_continues_past_failure_and_reports', function () {
    $teams = Team::factory()->count(10)->create(['provisioned_at' => now()]);
    $failing = $teams->first();
    $provisioner = app(FakeTenantProvisioner::class);
    $provisioner->failNextCallFor($failing->slug, 'migrateTenantDatabase');
    $migrator = new TenantFleetMigrator($provisioner);

    $report = $migrator->migrateAll(1);

    expect($report->succeeded)->toHaveCount(9);
    expect($report->failed)->toHaveKey($failing->slug);
    expect($report->hasFailures())->toBeTrue();
    // The other nine tenants were migrated despite the one failure.
    expect(Team::query()->where('schema_version', 1)->count())->toBe(9);
    expect($failing->fresh()->schema_version)->toBe(0);
});

test('migrate_all_is_resumable_after_fault_cleared', function () {
    $teams = Team::factory()->count(10)->create(['provisioned_at' => now()]);
    $failing = $teams->first();
    $provisioner = app(FakeTenantProvisioner::class);
    $provisioner->failNextCallFor($failing->slug, 'migrateTenantDatabase');
    $migrator = new TenantFleetMigrator($provisioner);

    $firstReport = $migrator->migrateAll(1);
    expect($firstReport->failed)->toHaveKey($failing->slug);

    // Fault is auto-cleared after firing once (simulating the underlying
    // D1 issue being fixed); rerunning brings the fleet fully to head,
    // and the nine already-migrated tenants are skipped, not re-migrated.
    $secondReport = $migrator->migrateAll(1);

    expect($secondReport->succeeded)->toBe([$failing->slug]);
    expect($secondReport->skipped)->toHaveCount(9);
    expect($secondReport->failed)->toBe([]);
    expect(Team::query()->where('schema_version', 1)->count())->toBe(10);
});

test('tenant_status_reports_version_skew', function () {
    $teams = Team::factory()->count(3)->create(['provisioned_at' => now()]);
    $teams[0]->forceFill(['schema_version' => 1])->save();
    $teams[1]->forceFill(['schema_version' => 1])->save();
    $teams[2]->forceFill(['schema_version' => 0])->save();

    $this->artisan('tenant:status')
        ->assertSuccessful()
        ->expectsOutputToContain('2')
        ->expectsOutputToContain('1');
});
