<?php

namespace App\Console\Commands;

use App\Models\Team;
use App\Services\Tenancy\TenantProvisioningService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Spec 09: `php artisan tenant:provision {org}`. Idempotent — an already
 * fully-provisioned org is a no-op that exits 0. A previously failed run
 * resumes from its failed step rather than starting over.
 */
#[Signature('tenant:provision {org : The team slug to provision} {--release=dev : The release version being deployed} {--region=us : The deployment region — immutable once provisioned (spec 11)}')]
#[Description('Provisions a tenant\'s dispatch script, D1 database, R2 prefix, and hostname')]
class TenantProvisionCommand extends Command
{
    public function handle(TenantProvisioningService $service): int
    {
        $slug = (string) $this->argument('org');
        $team = Team::query()->where('slug', $slug)->first();

        if ($team === null) {
            $this->components->error("No team with slug \"{$slug}\".");

            return self::FAILURE;
        }

        try {
            $service->provision($team, (string) $this->option('release'), (string) $this->option('region'));
        } catch (Throwable $exception) {
            $step = $team->fresh()?->provisioning_failed_step ?? 'unknown';
            $this->components->error("Provisioning failed at step \"{$step}\": {$exception->getMessage()}");

            return self::FAILURE;
        }

        $this->components->info("Provisioned \"{$slug}\".");

        return self::SUCCESS;
    }
}
