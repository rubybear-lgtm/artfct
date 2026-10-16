<?php

namespace App\Console\Commands;

use App\Models\Team;
use App\Services\Indexing\IndexBackfillService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('indexing:backfill {org : The team slug}')]
#[Description('Queues every live, not-yet-indexed artifact of one org for indexing, the same way the console reindex action does')]
class IndexBackfillCommand extends Command
{
    public function handle(IndexBackfillService $service): int
    {
        if (! config('indexing.enabled')) {
            $this->components->error('Indexing is turned off.');

            return self::FAILURE;
        }

        $slug = (string) $this->argument('org');
        $team = Team::query()->where('slug', $slug)->first();

        if ($team === null) {
            $this->components->error("No team with slug \"{$slug}\".");

            return self::FAILURE;
        }

        if ($service->actingAdmin($team) === null) {
            $this->components->error("Team \"{$slug}\" has no admin to act as.");

            return self::FAILURE;
        }

        ['queued' => $queued, 'skipped' => $skipped] = $service->backfill($team);

        $this->components->info("{$queued} artifact(s) queued for indexing, {$skipped} skipped (already indexed, revoked, unreadable, or already queued).");

        return self::SUCCESS;
    }
}
