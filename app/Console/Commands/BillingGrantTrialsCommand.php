<?php

namespace App\Console\Commands;

use App\Services\Billing\TeamTrialService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('billing:grant-trials {--existing : Grant a 90-day Team trial to existing free non-personal teams}')]
#[Description('Idempotently grant Stripe-backed Team trials to eligible free teams (RUB-529)')]
class BillingGrantTrialsCommand extends Command
{
    public function handle(TeamTrialService $trials): int
    {
        if (! $this->option('existing')) {
            $this->error('Pass --existing to grant trials to current free teams.');

            return self::FAILURE;
        }

        $granted = $trials->grantToExistingFreeTeams();
        $this->info("Granted {$granted} trial(s).");

        return self::SUCCESS;
    }
}
