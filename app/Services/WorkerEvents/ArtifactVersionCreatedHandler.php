<?php

namespace App\Services\WorkerEvents;

use App\Contracts\ArtifactContentSource;
use App\Enums\AuditEventType;
use App\Jobs\IndexArtifactJob;
use App\Models\Team;
use App\Services\Governance\AuditLogger;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * `artifact.version_created` -> `IndexArtifactJob` on the `indexing` queue
 * (spec 12) plus an `artifact.version_published` audit row (RUB-438). A new
 * version keeps the artifact id, so unlike `artifact.created` this always
 * re-indexes: the existing entry and its vectors are replaced with the current
 * version's content. The content read doubles as the ownership check: an
 * artifact that is not in the event's org is refused. Events can arrive out of
 * order, so the version stored is the one the `/content` read reports now
 * rather than the one the event carries.
 *
 * The audit is written from the event itself, so a version published with a
 * cross-org `edit` credential is traceable even when indexing is off.
 */
final class ArtifactVersionCreatedHandler
{
    public function __construct(
        private readonly ArtifactContentSource $content,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $event
     */
    public function handle(array $event): void
    {
        $data = is_array($event['data'] ?? null) ? $event['data'] : [];
        $artifactId = $data['artifact_id'] ?? null;
        $team = Team::query()->where('slug', $event['org_id'] ?? null)->first();

        if (! is_string($artifactId) || $artifactId === '' || $team === null) {
            Log::warning('artifact.version_created event for unknown team or missing artifact id.', ['org_id' => $event['org_id'] ?? null]);

            return;
        }

        if (! config('indexing.enabled')) {
            $this->recordPublish($team, $artifactId, $data);

            return;
        }

        try {
            $artifact = $this->content->fetch($team->slug, $artifactId);
        } catch (Throwable) {
            throw new \RuntimeException('Artifact content read failed; event will retry.');
        }

        // Audited only once the step that can throw (and so retry the event)
        // has passed, so a retried event never writes a second row.
        $this->recordPublish($team, $artifactId, $data);

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

    /**
     * @param  array<string, mixed>  $data
     */
    private function recordPublish(Team $team, string $artifactId, array $data): void
    {
        $this->audit->record(
            AuditEventType::ArtifactVersionPublished,
            $team,
            $this->actor($data['created_by'] ?? null),
            $this->target($artifactId, $data, $team),
            'worker',
            'worker-event',
        );
    }

    /**
     * The editor's user id, or `unknown` when the event did not carry one.
     */
    private function actor(mixed $value): string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }

        if (is_int($value)) {
            return (string) $value;
        }

        return 'unknown';
    }

    /**
     * `artifact:{id} version:{n}`, plus ` by {org}` when a credential from
     * another org published this version (a public + edit cross-team edit).
     *
     * @param  array<string, mixed>  $data
     */
    private function target(string $artifactId, array $data, Team $team): string
    {
        $version = $data['version'] ?? null;
        $label = is_numeric($version) ? (string) (int) $version : 'unknown';
        $target = "artifact:{$artifactId} version:{$label}";

        $editorOrg = $data['editor_org'] ?? null;
        if (is_string($editorOrg) && $editorOrg !== '' && $editorOrg !== $team->slug) {
            $target .= " by {$editorOrg}";
        }

        return $target;
    }
}
