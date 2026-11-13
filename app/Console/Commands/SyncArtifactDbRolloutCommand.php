<?php

namespace App\Console\Commands;

use App\Jobs\SyncArtifactDbTeam;
use App\Models\Team;
use Illuminate\Console\Command;
use Throwable;

class SyncArtifactDbRolloutCommand extends Command
{
    protected $signature = 'artifact-db:sync-rollout';

    protected $description = 'Push each team\'s artifact database release to the Worker and report any team that did not take it.';

    public function handle(): int
    {
        $synced = 0;
        $failed = [];

        Team::query()->orderBy('id')->each(function (Team $team) use (&$synced, &$failed): void {
            try {
                SyncArtifactDbTeam::dispatchSync($team->id);
                $synced++;
            } catch (Throwable $exception) {
                $failed[] = $team->slug;
            }
        });

        $this->info("Pushed the artifact database release for {$synced} teams.");

        if ($failed !== []) {
            $this->error('Not accepted by the Worker: '.implode(', ', $failed).'. These teams keep whatever gate the Worker last had.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
