<?php

namespace App\Console\Commands;

use App\Models\Team;
use App\Services\Governance\RetentionService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Spec 11: applies a team's retention policy. Defaults to `--dry-run` —
 * the safer, primary path; pass `--apply` to actually hard-delete.
 */
#[Signature('governance:retention {org : The team slug} {--days= : Override the org retention policy} {--apply : Actually hard-delete; without this the run is a dry run}')]
#[Description('Reports (or applies) the retention job for one org, skipping legally held artifacts')]
class GovernanceRetentionCommand extends Command
{
    public function handle(RetentionService $service): int
    {
        $slug = (string) $this->argument('org');
        $team = Team::query()->where('slug', $slug)->first();

        if ($team === null) {
            $this->components->error("No team with slug \"{$slug}\".");

            return self::FAILURE;
        }

        $days = $this->option('days') !== null ? (int) $this->option('days') : null;
        $dryRun = ! $this->option('apply');

        $plan = $service->apply($team, $days, $dryRun, actor: 'cli');

        $this->components->info($dryRun
            ? sprintf('Dry run: would delete %d artifact(s), %d held survivor(s).', count($plan->toDelete), count($plan->heldSurvivors))
            : sprintf('Deleted %d artifact(s), %d held survivor(s).', count($plan->toDelete), count($plan->heldSurvivors)));

        return self::SUCCESS;
    }
}
