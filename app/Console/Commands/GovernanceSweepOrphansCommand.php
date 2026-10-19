<?php

namespace App\Console\Commands;

use App\Models\Team;
use App\Services\Governance\ArtifactGovernanceContract;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * RUB-317: deletes blobs the Worker no longer has any artifact reference
 * for (left behind when an R2 delete failed after a hard delete).
 */
#[Signature('governance:sweep-orphans {org : The team slug}')]
#[Description('Removes zero-reference blobs left behind by a failed hard delete')]
class GovernanceSweepOrphansCommand extends Command
{
    public function handle(ArtifactGovernanceContract $governance): int
    {
        $slug = (string) $this->argument('org');

        if (! Team::query()->where('slug', $slug)->exists()) {
            $this->components->error("No team with slug \"{$slug}\".");

            return self::FAILURE;
        }

        try {
            $removed = $governance->sweepOrphans($slug);
        } catch (\Throwable $exception) {
            $this->components->error('Aborted: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Removed {$removed} orphaned blob(s).");

        return self::SUCCESS;
    }
}
