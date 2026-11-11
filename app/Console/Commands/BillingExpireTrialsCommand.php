<?php

namespace App\Console\Commands;

use App\Enums\Plan;
use App\Models\Team;
use App\Services\Billing\TeamTrialService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('billing:expire-trials')]
#[Description('Drop Team trials whose mirrored trial_ends_at is past (Stripe webhook safety net)')]
class BillingExpireTrialsCommand extends Command
{
    public function handle(TeamTrialService $trials): int
    {
        $expired = 0;
        $failed = 0;

        Team::query()
            ->where('plan', Plan::Team)
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<=', now())
            ->orderBy('id')
            ->each(function (Team $team) use ($trials, &$expired, &$failed): void {
                try {
                    if ($trials->expire($team, 'scheduler')) {
                        $expired++;
                    }
                } catch (Throwable $exception) {
                    $failed++;
                    report($exception);
                    $this->error("Failed to expire trial for team {$team->slug}: {$exception->getMessage()}");
                }
            });

        $this->info("Expired {$expired} trial(s). Failures: {$failed}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
