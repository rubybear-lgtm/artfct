<?php

namespace App\Services\WorkerEvents;

use App\Models\Team;
use App\Services\Collections\CollectionService;
use App\Services\Indexing\ArtifactTextPurger;
use App\Services\Indexing\IndexingService;
use Illuminate\Support\Facades\Log;

final class ArtifactDeletedHandler
{
    public function __construct(
        private readonly IndexingService $indexer,
        private readonly CollectionService $collections,
    ) {}

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
        ArtifactTextPurger::purgeEverything($team, $artifactId);
        // Worker deletes (console, retention, etc.) never hit DeleteArtifactTool,
        // so drop collection memberships here too (RUB-515 item 6).
        $this->collections->forgetArtifact($team, $artifactId);
    }
}
