<?php

namespace App\Jobs;

use App\Models\Team;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Fans the `artifact-db` feature release out to the Worker, one queued job per
 * team ({@see SyncArtifactDbTeam}), so a long team list never runs into one
 * job's timeout and a failed push retries on its own.
 *
 * Laravel decides who has the artifact database (the release in /ops); the
 * Worker enforces it per org. Run after a release changes, and from
 * `artifact-db:sync-rollout`.
 */
class SyncArtifactDbRollout implements ShouldQueue
{
    use Queueable;

    public const FEATURE = 'artifact-db';

    public function handle(): void
    {
        Team::query()->orderBy('id')->chunkById(100, function ($teams): void {
            foreach ($teams as $team) {
                SyncArtifactDbTeam::dispatch($team->id);
            }
        });
    }
}
