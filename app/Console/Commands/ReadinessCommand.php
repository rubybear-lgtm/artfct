<?php

namespace App\Console\Commands;

use App\Services\Readiness\IntegrationReadiness;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Reports which external integrations are configured on this environment
 * (never their values) and exits non-zero when one is half-configured or,
 * with --strict, missing.
 */
#[Signature('app:readiness {--strict : Fail when an integration is not configured at all}')]
#[Description('Report which sign-in, payment and email integrations are configured')]
class ReadinessCommand extends Command
{
    public function handle(IntegrationReadiness $readiness): int
    {
        $rows = [];
        $failed = false;

        foreach ($readiness->check() as $integration) {
            $state = $integration['state'];

            $failed = $failed
                || $state === IntegrationReadiness::INCOMPLETE
                || ($state === IntegrationReadiness::NOT_CONFIGURED && $this->option('strict'));
            $rows[] = [$integration['name'], $state, implode(', ', $integration['missing']) ?: '-'];
        }

        $this->table(['Integration', 'State', 'Missing config'], $rows);

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
