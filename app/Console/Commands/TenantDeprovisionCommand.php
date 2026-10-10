<?php

namespace App\Console\Commands;

use App\Models\Team;
use App\Services\Tenancy\TenantProvisioningService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Spec 09: `php artisan tenant:deprovision {org}`. Removes the dispatch
 * script only; D1 and R2 are retained for the retention window (spec 11).
 */
#[Signature('tenant:deprovision {org : The team slug to deprovision}')]
#[Description('Removes a tenant\'s dispatch script; D1 and R2 are retained for the retention window')]
class TenantDeprovisionCommand extends Command
{
    public function handle(TenantProvisioningService $service): int
    {
        $slug = (string) $this->argument('org');
        $team = Team::query()->where('slug', $slug)->first();

        if ($team === null) {
            $this->components->error("No team with slug \"{$slug}\".");

            return self::FAILURE;
        }

        $service->deprovision($team);
        $this->components->info("Deprovisioned \"{$slug}\" (D1/R2 retained for the retention window).");

        return self::SUCCESS;
    }
}
