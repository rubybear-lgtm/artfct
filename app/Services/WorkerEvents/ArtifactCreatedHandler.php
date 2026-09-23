<?php

namespace App\Services\WorkerEvents;

use App\Contracts\ArtifactContentSource;
use App\Jobs\IndexArtifactJob;
use App\Models\ArtifactIndexEntry;
use App\Models\Team;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * `artifact.created` -> `IndexArtifactJob` on the `indexing` queue
 * (spec 12). Dedupe by event id already happened in the controller; an
 * already-indexed artifact is skipped here. The content read doubles as the
 * ownership check: an artifact that is not in the event's org is refused.
 */
final class ArtifactCreatedHandler
{
    public function __construct(private readonly ArtifactContentSource $content) {}

    /**
     * @param  array<string, mixed>  $event
     */
    public function handle(array $event): void
    {
        if (! config('indexing.enabled')) {
            return;
        }

        $artifactId = $event['data']['artifact_id'] ?? null;
        $team = Team::query()->where('slug', $event['org_id'])->first();

        if (! is_string($artifactId) || $team === null) {
            Log::warning('artifact.created event for unknown team or missing artifact id.', ['org_id' => $event['org_id']]);

            return;
        }

        if (ArtifactIndexEntry::query()->where('team_id', $team->id)->where('artifact_id', $artifactId)->exists()) {
            return;
        }

        try {
            $artifact = $this->content->fetch($team->slug, $artifactId);
        } catch (Throwable $exception) {
            Log::warning('artifact.created content read failed.', ['artifact_id' => $artifactId, 'error' => $exception->getMessage()]);

            return;
        }

        if ($artifact === null) {
            Log::warning('artifact.created refused: artifact not found in the event org.', [
                'org_id' => $event['org_id'],
                'artifact_id' => $artifactId,
            ]);

            return;
        }

        IndexArtifactJob::dispatch($team->id, $artifactId, $artifact['html'], $artifact['provenance'])
            ->onQueue('indexing');
    }
}
