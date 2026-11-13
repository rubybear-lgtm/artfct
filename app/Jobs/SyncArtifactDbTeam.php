<?php

namespace App\Jobs;

use App\Models\Team;
use App\Services\FeatureFlags;
use App\Services\Sharing\OrgSettingsWriter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Pushes one team's `artifact-db` gate to the Worker.
 *
 * It throws when the Worker did not take the push, so the queue retries it with
 * a backoff and a team does not stay open at the Worker after staff withdrew
 * the release. A push that still fails after the last attempt is logged as an
 * error; `artifact-db:sync-rollout` reconciles by hand and exits non-zero while
 * any team is unsynced.
 */
class SyncArtifactDbTeam implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 30;

    public function __construct(public readonly int $teamId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30, 60, 120];
    }

    public function handle(FeatureFlags $flags, OrgSettingsWriter $writer): void
    {
        $team = Team::query()->find($this->teamId);

        if ($team === null) {
            return;
        }

        if (! $writer->pushArtifactDb($team, $flags->enabled(SyncArtifactDbRollout::FEATURE, $team))) {
            throw new RuntimeException("The artifact database gate for team {$team->slug} was not accepted by the Worker.");
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Artifact database gate push gave up.', ['team_id' => $this->teamId, 'error' => $exception->getMessage()]);
    }
}
