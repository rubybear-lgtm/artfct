<?php

namespace App\Console\Commands;

use App\Contracts\ArtifactContentSource;
use App\Contracts\ArtifactDirectory;
use App\Jobs\IndexArtifactJob;
use App\Models\ArtifactIndexEntry;
use App\Models\ArtifactIndexingFailure;
use App\Models\Team;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

#[Signature('indexing:backfill {org : The team slug}')]
#[Description('Queues every live, not-yet-indexed artifact of one org for indexing, the same way the console reindex action does')]
class IndexBackfillCommand extends Command
{
    public function handle(ArtifactDirectory $directory, ArtifactContentSource $content): int
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

        $indexed = ArtifactIndexEntry::query()->where('team_id', $team->id)->pluck('artifact_id')->all();
        $failed = ArtifactIndexingFailure::query()->where('team_id', $team->id)->pluck('artifact_id')->all();
        $queued = 0;
        $skipped = 0;
        $cursor = null;

        do {
            $page = $directory->listArtifacts($team->slug, [], $cursor, 100);

            foreach ($page['artifacts'] as $artifact) {
                $artifactId = (string) $artifact['id'];
                $alreadyIndexed = in_array($artifactId, $indexed, true) && ! in_array($artifactId, $failed, true);
                $fetched = $alreadyIndexed || ! empty($artifact['revoked_at']) ? null : $content->fetch($team->slug, $artifactId);

                if ($fetched === null || ! Cache::add(IndexArtifactJob::reindexCacheKey($team->id, $artifactId), true, now()->addMinutes(10))) {
                    $skipped++;

                    continue;
                }

                IndexArtifactJob::dispatch($team->id, $artifactId, $fetched['html'], $fetched['provenance'])->onQueue('indexing');
                $queued++;
            }

            $cursor = $page['next_cursor'];
        } while ($cursor !== null);

        $this->components->info("{$queued} artifact(s) queued for indexing, {$skipped} skipped (already indexed, revoked, unreadable, or already queued).");

        return self::SUCCESS;
    }
}
