<?php

namespace App\Services\WorkerEvents;

use App\Contracts\ArtifactContentSource;
use App\Jobs\IndexArtifactJob;
use App\Models\Team;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * `artifact.version_created` -> `IndexArtifactJob` on the `indexing` queue
 * (spec 12). A new version keeps the artifact id, so unlike `artifact.created`
 * this always re-indexes: the existing entry and its vectors are replaced with
 * the current version's content. The content read doubles as the ownership
 * check: an artifact that is not in the event's org is refused. Events can
 * arrive out of order, so the version stored is the one the `/content` read
 * reports now rather than the one the event carries.
 */
final class ArtifactVersionCreatedHandler
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
            Log::warning('artifact.version_created event for unknown team or missing artifact id.', ['org_id' => $event['org_id']]);

            return;
        }

        try {
            $artifact = $this->content->fetch($team->slug, $artifactId);
        } catch (Throwable) {
            throw new \RuntimeException('Artifact content read failed; event will retry.');
        }

        if ($artifact === null) {
            Log::warning('artifact.version_created refused: artifact not found in the event org.', [
                'org_id' => $event['org_id'],
                'artifact_id' => $artifactId,
            ]);

            return;
        }

        IndexArtifactJob::dispatch($team->id, $artifactId, $artifact['html'], $artifact['provenance'], $artifact['version'])
            ->onQueue('indexing');
    }
}
