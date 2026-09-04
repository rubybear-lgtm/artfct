<?php

namespace App\Jobs;

use App\Models\ArtifactIndexingFailure;
use App\Models\Team;
use App\Services\Indexing\IndexingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * `artifact.created` -> Queue -> render -> extract -> chunk -> embed ->
 * upsert (spec 12). Dispatching this never blocks the deploy response —
 * DoD: "Indexing never blocks a deploy — deploy latency is unchanged with
 * the queue backed up." A render timeout (or any other failure) retries
 * with backoff, then `failed()` dead-letters it with the reason.
 */
class IndexArtifactJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** @var int */
    public $tries = 3;

    /**
     * @param  array{agent: ?string, repo_url: ?string, commit_sha: ?string}  $provenance
     */
    public function __construct(
        public readonly int $teamId,
        public readonly string $artifactId,
        public readonly string $html,
        public readonly array $provenance,
    ) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(IndexingService $indexer): void
    {
        $team = Team::query()->findOrFail($this->teamId);
        $indexer->indexArtifact($team, $this->artifactId, $this->html, $this->provenance);
    }

    /**
     * Dead-letters the attempt: recorded with the reason, surfaced for the
     * console, and — critically — never touches the artifact's own row or
     * serving path (DoD: "A dead-lettered artifact still serves
     * normally").
     */
    public function failed(Throwable $exception): void
    {
        ArtifactIndexingFailure::query()->create([
            'team_id' => $this->teamId,
            'artifact_id' => $this->artifactId,
            'attempts' => $this->attempts(),
            'reason' => $exception->getMessage(),
            'failed_at' => now(),
        ]);
    }
}
