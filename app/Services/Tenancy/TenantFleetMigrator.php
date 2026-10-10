<?php

namespace App\Services\Tenancy;

use App\Models\Team;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Runs pending migrations across every provisioned tenant's D1 (spec 09:
 * "the recurring cost — the price floor"). Partial failure is expected,
 * not exceptional: one tenant's D1 failing does not stop the run, and the
 * report names every tenant that failed so a caller can decide whether to
 * exit non-zero. Resumable — a rerun only touches tenants not already at
 * the target schema version, so a previously-failed tenant is retried and
 * an already-migrated tenant is skipped, not re-migrated.
 */
final class TenantFleetMigrator
{
    public function __construct(
        private readonly TenantProvisionerContract $provisioner,
    ) {}

    public function migrateAll(int $targetSchemaVersion): FleetMigrationReport
    {
        $succeeded = [];
        $failed = [];
        $skipped = [];

        /** @var Collection<int, Team> $tenants */
        $tenants = Team::query()->whereNotNull('provisioned_at')->get();

        foreach ($tenants as $team) {
            if ($team->schema_version >= $targetSchemaVersion) {
                $skipped[] = $team->slug;

                continue;
            }

            try {
                $newVersion = $this->provisioner->migrateTenantDatabase($team->slug);
                $team->schema_version = $newVersion;
                $team->save();
                $succeeded[] = $team->slug;
            } catch (Throwable $exception) {
                $failed[$team->slug] = $exception->getMessage();
            }
        }

        return new FleetMigrationReport($succeeded, $failed, $skipped);
    }
}
