<?php

namespace App\Services\WorkerEvents;

use App\Models\ArtifactIndexEntry;
use App\Models\ArtifactIndexingFailure;
use App\Models\Team;
use App\Services\Indexing\IndexingService;
use Illuminate\Support\Facades\Log;

final class ArtifactDeletedHandler
{
    public function __construct(private readonly IndexingService $indexer) {}

    /** @param array<string, mixed> $event */
    public function handle(array $event): void
    {
        $artifactId = $event['data']['artifact_id'] ?? null;
        $team = Team::query()->where('slug', $event['org_id'])->first();

        if (! is_string($artifactId) || $team === null) {
            Log::warning('artifact.deleted event for unknown team or missing artifact id.', ['org_id' => $event['org_id']]);

            return;
        }

        $this->indexer->removeFromIndex($team, $artifactId);
        ArtifactIndexEntry::query()->where('team_id', $team->id)->where('artifact_id', $artifactId)->delete();
        ArtifactIndexingFailure::query()->where('team_id', $team->id)->where('artifact_id', $artifactId)->delete();
    }
}
