<?php

namespace App\Services\Indexing;

use App\Contracts\ArtifactContentSource;
use App\Contracts\ArtifactDirectory;
use App\Jobs\IndexArtifactJob;
use App\Models\ArtifactIndexEntry;
use App\Models\ArtifactIndexingFailure;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use LogicException;
use Throwable;

/**
 * Queues artifacts of one org for indexing. The one implementation behind
 * `indexing:backfill`, the console's reindex action and the operator
 * console's reindex job (spec 17), so the three cannot drift apart.
 */
final class IndexBackfillService
{
    public function __construct(
        private readonly ArtifactDirectory $directory,
        private readonly ArtifactContentSource $content,
    ) {}

    /**
     * The directory mints its Worker credential for the signed-in user, so a
     * backfill acts as the team's first admin.
     */
    public function actingAdmin(Team $team): ?User
    {
        return $team->firstAdmin();
    }

    /**
     * Queues every live, not-yet-indexed (or dead-lettered) artifact.
     *
     * @return array{queued: int, skipped: int}
     *
     * @throws LogicException when the team has no admin to act as.
     */
    public function backfill(Team $team): array
    {
        $admin = $this->actingAdmin($team);
        if ($admin === null) {
            throw new LogicException("Team \"{$team->slug}\" has no admin to act as.");
        }
        auth()->setUser($admin);

        $indexed = ArtifactIndexEntry::query()->where('team_id', $team->id)->pluck('artifact_id')->all();
        $failed = ArtifactIndexingFailure::query()->where('team_id', $team->id)->pluck('artifact_id')->all();
        $queued = 0;
        $skipped = 0;
        $cursor = null;

        do {
            $page = $this->directory->listArtifacts($team->slug, [], $cursor, 100);

            foreach ($page['artifacts'] as $artifact) {
                $artifactId = (string) $artifact['id'];
                $alreadyIndexed = in_array($artifactId, $indexed, true) && ! in_array($artifactId, $failed, true);
                $fetched = $alreadyIndexed || ! empty($artifact['revoked_at']) ? null : $this->content->fetch($team->slug, $artifactId);

                if ($fetched === null || ! Cache::add(IndexArtifactJob::reindexCacheKey($team->id, $artifactId), true, now()->addMinutes(10))) {
                    $skipped++;

                    continue;
                }

                IndexArtifactJob::dispatch($team->id, $artifactId, $fetched['html'], $fetched['provenance'], $fetched['version'] ?? null)->onQueue('indexing');
                $queued++;
            }

            $cursor = $page['next_cursor'];
        } while ($cursor !== null);

        return ['queued' => $queued, 'skipped' => $skipped];
    }

    /**
     * Queues one artifact, the way the console's reindex action does: an
     * artifact that is already indexed (and not dead-lettered) is a no-op, and
     * a second call while the first is still queued is a no-op. Returns
     * whether a job was queued.
     */
    public function reindexArtifact(Team $team, string $artifactId): bool
    {
        $alreadyIndexed = ArtifactIndexEntry::query()->where('team_id', $team->id)->where('artifact_id', $artifactId)->exists();
        $hasFailure = ArtifactIndexingFailure::query()->where('team_id', $team->id)->where('artifact_id', $artifactId)->exists();

        if ($alreadyIndexed && ! $hasFailure) {
            return false;
        }

        $reindexCacheKey = IndexArtifactJob::reindexCacheKey($team->id, $artifactId);
        if (! Cache::add($reindexCacheKey, true, now()->addMinutes(10))) {
            return false;
        }

        try {
            $artifact = $this->content->fetch($team->slug, $artifactId);

            if ($artifact === null) {
                Cache::forget($reindexCacheKey);

                return false;
            }

            IndexArtifactJob::dispatch($team->id, $artifactId, $artifact['html'], $artifact['provenance'], $artifact['version'] ?? null)->onQueue('indexing');
        } catch (Throwable $exception) {
            Cache::forget($reindexCacheKey);

            throw $exception;
        }

        return true;
    }
}
