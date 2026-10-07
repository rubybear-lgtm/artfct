<?php

namespace App\Services\WorkerEvents;

use App\Enums\AuditEventType;
use App\Models\Team;
use App\Services\Governance\AuditLogger;
use Illuminate\Support\Facades\Log;

/**
 * `artifact.sharing_changed` -> one audit row (RUB-438). The Worker emits this
 * whenever an artifact moves between private/team/public or changes edit
 * access; the target records the transition so the audit page shows what
 * changed, not just that something did.
 */
final class ArtifactSharingChangedHandler
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $event
     */
    public function handle(array $event): void
    {
        $data = is_array($event['data'] ?? null) ? $event['data'] : [];
        $artifactId = $data['artifact_id'] ?? null;
        $team = Team::query()->where('slug', $event['org_id'] ?? null)->first();

        if (! is_string($artifactId) || $artifactId === '' || $team === null) {
            Log::warning('artifact.sharing_changed event for unknown team or missing artifact id.', [
                'org_id' => $event['org_id'] ?? null,
            ]);

            return;
        }

        $from = is_string($data['from'] ?? null) ? $data['from'] : 'unknown';
        $to = is_string($data['to'] ?? null) ? $data['to'] : 'unknown';
        $target = "artifact:{$artifactId} {$from}->{$to}";

        $fromEdit = $data['from_edit_access'] ?? null;
        $toEdit = $data['to_edit_access'] ?? null;
        if (is_string($fromEdit) && is_string($toEdit) && $fromEdit !== $toEdit) {
            $target .= " edit_access {$fromEdit}->{$toEdit}";
        }

        $this->audit->record(
            AuditEventType::ArtifactSharingChanged,
            $team,
            $this->actor($data['actor_user_id'] ?? null),
            $target,
            'worker',
            'worker-event',
        );
    }

    private function actor(mixed $value): string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }

        if (is_int($value)) {
            return (string) $value;
        }

        return 'system';
    }
}
