<?php

namespace App\Console\Commands;

use App\Services\Tenancy\TenantFleetMigrator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Spec 09: `php artisan tenant:migrate --all`. Runs across the fleet,
 * never stopping at the first failure — every tenant is attempted, and
 * the command exits non-zero naming every one that failed. Resumable: a
 * rerun skips tenants already at the target schema version.
 */
#[Signature('tenant:migrate {--all : Migrate every provisioned tenant} {--target=1 : Target schema version}')]
#[Description('Runs pending migrations across the tenant fleet, collecting per-tenant failures')]
class TenantMigrateCommand extends Command
{
    public function handle(TenantFleetMigrator $migrator): int
    {
        if (! $this->option('all')) {
            $this->components->error('Only --all is supported (per-tenant targeting is not yet built).');

            return self::FAILURE;
        }

        $report = $migrator->migrateAll((int) $this->option('target'));

        foreach ($report->succeeded as $slug) {
            $this->components->info("{$slug}: migrated to head");
        }
        foreach ($report->skipped as $slug) {
            $this->components->info("{$slug}: already at head");
        }
        foreach ($report->failed as $slug => $message) {
            $this->components->error("{$slug}: {$message}");
        }

        $this->newLine();
        $this->line(sprintf(
            '%d succeeded, %d skipped, %d failed.',
            count($report->succeeded),
            count($report->skipped),
            count($report->failed),
        ));

        return $report->hasFailures() ? self::FAILURE : self::SUCCESS;
    }
}
