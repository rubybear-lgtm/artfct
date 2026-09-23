<?php

namespace App\Services\WorkerEvents;

use App\Enums\AuditEventType;
use App\Models\McpActivity;
use App\Models\Team;
use App\Services\Collections\UsageEventLogger;
use App\Services\Governance\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Persists the Worker's successful permanent-artifact view signal without
 * trusting organization or actor values beyond the signed event boundary.
 */
final class ArtifactViewedHandler
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly UsageEventLogger $usage,
    ) {}

    /**
     * @param  array<string, mixed>  $event
     */
    public function handle(array $event): void
    {
        $artifactId = $event['data']['artifact_id'] ?? null;
        $team = Team::query()->where('slug', $event['org_id'])->first();

        if (! is_string($artifactId) || $artifactId === '' || $team === null) {
            Log::warning('artifact.viewed event for unknown team or missing artifact id.', [
                'org_id' => $event['org_id'] ?? null,
            ]);

            return;
        }

        $viewerUserId = $this->viewerUserId($event['data']['viewer_user_id'] ?? null);
        $actor = $viewerUserId === null ? 'anonymous' : (string) $viewerUserId;
        $occurredAt = $this->occurredAt($event['occurred_at'] ?? null);

        $this->audit->record(
            AuditEventType::ArtifactViewed,
            $team,
            $actor,
            "artifact:{$artifactId}",
            'worker',
            'worker-event',
        );

        if ($viewerUserId === null) {
            $this->usage->recordAnonymousView($team, $artifactId, $occurredAt);

            return;
        }

        $retrievedAt = $occurredAt ?? Carbon::now();
        $wasRetrieved = McpActivity::query()
            ->where('team_id', $team->id)
            ->where('tool', 'get_artifact')
            ->where('artifact_id', $artifactId)
            ->where('actor', (string) $viewerUserId)
            ->where('outcome', 'success')
            ->where('created_at', '>=', $retrievedAt->copy()->subMinutes(30))
            ->where('created_at', '<=', $retrievedAt->copy()->addMinute())
            ->exists();

        if ($wasRetrieved) {
            $this->usage->recordRetrievedThenOpened($team, $artifactId, $viewerUserId, $occurredAt);

            return;
        }

        $this->usage->recordView($team, $artifactId, $viewerUserId, $occurredAt);
    }

    private function viewerUserId(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        return null;
    }

    private function occurredAt(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
