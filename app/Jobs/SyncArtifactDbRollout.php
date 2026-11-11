<?php

namespace App\Jobs;

use App\Models\Team;
use App\Services\FeatureFlags;
use App\Services\Sharing\OrgSettingsWriter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Pushes the `artifact-db` feature release to the Worker, one team at a time.
 *
 * Laravel decides who has the artifact database (the release in /ops); the
 * Worker enforces it per org. Run after a release changes, and from
 * `artifact-db:sync-rollout`. A team the Worker could not be told about is
 * logged by the writer and stays on whatever the Worker last knew, which is
 * off unless it was explicitly enabled, so a failed push can only withhold.
 */
class SyncArtifactDbRollout implements ShouldQueue
{
    use Queueable;

    public const FEATURE = 'artifact-db';

    public function handle(FeatureFlags $flags, OrgSettingsWriter $writer): void
    {
        Team::query()->orderBy('id')->chunkById(100, function ($teams) use ($flags, $writer): void {
            foreach ($teams as $team) {
                $writer->pushArtifactDb($team, $flags->enabled(self::FEATURE, $team));
            }
        });
    }
}
