<?php

namespace App\Console\Commands;

use App\Enums\AuditEventType;
use App\Models\Team;
use App\Services\Billing\OrgLimitsWriter;
use App\Services\Operator\OperatorAuditLogger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * RUB-310: pushes each org's current limits and payment state to the
 * Worker. Reconciles a push that was dropped (the Worker fails open on a
 * lost push), so it also runs on a schedule to bound that window.
 */
#[Signature('billing:sync-limits {org? : The team slug; omit to sync every team} {--reason= : Why an operator is running this (5 to 500 characters); records the same audit event as the console\'s "Push limits" action. Leave it off for routine runs}')]
#[Description('Pushes quota limits and payment state to the Worker')]
class BillingSyncLimitsCommand extends Command
{
    public function handle(OrgLimitsWriter $writer, OperatorAuditLogger $audit): int
    {
        $reason = $this->option('reason');

        if ($reason !== null && ! OperatorAuditLogger::isValidReason((string) $reason)) {
            $this->components->error('--reason must be 5 to 500 characters.');

            return self::FAILURE;
        }

        $slug = $this->argument('org');
        $teams = Team::query()
            ->when(is_string($slug), fn ($query) => $query->where('slug', $slug))
            ->get();

        if ($teams->isEmpty()) {
            $this->components->error(is_string($slug) ? "No team with slug \"{$slug}\"." : 'No teams to sync.');

            return self::FAILURE;
        }

        $failed = 0;
        foreach ($teams as $team) {
            $pushed = $writer->push($team);

            if ($pushed) {
                $this->components->info("Pushed limits for {$team->slug}.");
            } else {
                $failed++;
                $this->components->error("Could not push limits for {$team->slug}.");
            }

            if ($reason !== null) {
                $audit->recordForCli(AuditEventType::OperatorLimitsChanged, $team, $team->slug, trim((string) $reason), $pushed ? 'success' : 'failure');
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
