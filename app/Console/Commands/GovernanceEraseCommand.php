<?php

namespace App\Console\Commands;

use App\Models\Team;
use App\Services\Governance\ErasureService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Spec 11: GDPR erasure across an org. Defaults to `--dry-run`; pass
 * `--apply` to actually hard-delete. Refuses (whole, never partial) when
 * any referencing artifact is under legal hold.
 */
#[Signature('governance:erase {org : The team slug} {--apply : Actually hard-delete; without this the run is a dry run}')]
#[Description('Plans or executes a GDPR erasure across every artifact in one org')]
class GovernanceEraseCommand extends Command
{
    public function handle(ErasureService $service): int
    {
        $slug = (string) $this->argument('org');
        $team = Team::query()->where('slug', $slug)->first();

        if ($team === null) {
            $this->components->error("No team with slug \"{$slug}\".");

            return self::FAILURE;
        }

        $dryRun = ! $this->option('apply');
        try {
            $plan = $service->erase($team, $dryRun, actor: 'cli');
        } catch (\Throwable $exception) {
            $this->components->error('Aborted: '.$exception->getMessage());

            return self::FAILURE;
        }

        if ($plan->refused) {
            $this->components->error("Refused: artifact [{$plan->heldArtifactId}] is under legal hold.");

            return self::FAILURE;
        }

        $this->components->info($dryRun
            ? sprintf('Dry run: would erase %d artifact(s).', count($plan->artifactIds))
            : sprintf('Erased %d artifact(s).', count($plan->artifactIds)));

        return self::SUCCESS;
    }
}
