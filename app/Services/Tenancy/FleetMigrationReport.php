<?php

namespace App\Services\Tenancy;

/**
 * Result of one {@see TenantFleetMigrator::migrateAll()} run.
 */
final class FleetMigrationReport
{
    /**
     * @param  list<string>  $succeeded  Org slugs newly migrated to head this run.
     * @param  array<string, string>  $failed  Org slug -> failure message, for every tenant this run could not migrate.
     * @param  list<string>  $skipped  Org slugs already at the target version before this run started.
     */
    public function __construct(
        public readonly array $succeeded,
        public readonly array $failed,
        public readonly array $skipped,
    ) {}

    public function hasFailures(): bool
    {
        return $this->failed !== [];
    }
}
