<?php

namespace App\Console\Commands;

use App\Jobs\SyncArtifactDbRollout;
use Illuminate\Console\Command;

class SyncArtifactDbRolloutCommand extends Command
{
    protected $signature = 'artifact-db:sync-rollout';

    protected $description = 'Push each team\'s artifact database release to the Worker.';

    public function handle(SyncArtifactDbRollout $job): int
    {
        app()->call([$job, 'handle']);
        $this->info('Pushed the artifact database release for every team.');

        return self::SUCCESS;
    }
}
