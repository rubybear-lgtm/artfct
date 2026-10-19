<?php

namespace App\Console\Commands;

use App\Models\Team;
use App\Services\Billing\QuotaService;
use App\Services\Synthetic\SyntheticScenario;
use App\Services\Synthetic\SyntheticSeeder;
use App\Services\Synthetic\WorkerTarget;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * Walks the synthetic org through every quota and payment state on staging
 * and prints what the meters would show (RUB-335). It runs inside the
 * deployment, where the database and Worker secrets already are, and does
 * nothing unless the environment is `staging` and
 * `STAGING_VERIFY_USAGE=true`, so it is safe to leave wired into the deploy.
 */
#[Signature('staging:verify-usage')]
#[Description('Staging only: cycle the synthetic org through quota and payment states and print the meters')]
class StagingVerifyUsageCommand extends Command
{
    public function handle(SyntheticScenario $scenario, QuotaService $quota): int
    {
        if (! app()->environment('staging') || ! config('synthetic.verify_usage')) {
            return self::SUCCESS;
        }

        $slug = 'zz-northwind';
        SyntheticSeeder::assertSafe($slug);

        if (! Team::query()->where('slug', $slug)->exists()) {
            Artisan::call('synthetic:seed', ['--laravel-only' => true, '--target' => 'staging']);
        }

        $team = Team::query()->where('slug', $slug)->firstOrFail();

        // The staging Worker is the one this app already talks to.
        config(['synthetic.targets.staging.a' => [
            'url' => config('services.worker.base_url'),
            'token' => config('services.worker.org_token'),
            'governance_secret' => config('services.worker.governance_secret'),
            'limits_secret' => config('services.worker.limits_write_secret'),
            'persist_to' => null,
            'wrangler_config' => null,
            'remote' => false,
        ]]);
        $worker = WorkerTarget::for('staging', 'a', $slug);

        foreach (['near-quota', 'over-quota', 'past-due', 'healthy'] as $name) {
            $result = $scenario->apply($team, $name, $worker);
            $status = $quota->status($team->fresh());

            $this->line('USAGE_VERIFY '.json_encode([
                'scenario' => $name,
                'pushed' => $result['pushed'],
                'payment' => $result['payment_status']->value,
                'storagePercent' => round($status->storagePercent * 100, 1),
                'warning' => $status->anyWarning(),
                'exceeded' => $status->anyExceeded(),
            ]));
        }

        return self::SUCCESS;
    }
}
