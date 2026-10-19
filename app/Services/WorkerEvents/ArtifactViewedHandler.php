<?php

namespace App\Services\WorkerEvents;

use App\Enums\AuditEventType;
use App\Models\McpActivity;
use App\Models\SearchResultServed;
use App\Models\Team;
use App\Services\Collections\UsageEventLogger;
use App\Services\Governance\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Persists the Worker's successful permanent-artifact view signal without
 * trusting organization or actor values beyond the signed event boundary.
 *
 * Two independent "retrieved, then opened" correlations feed the same
 * `RetrievedThenOpened` usage event (RUB-314): an MCP `get_artifact` call by
 * the same actor within a 30-minute-before/1-minute-after window (identity-
 * based, only possible for an identified viewer), and a `search_artifacts`
 * result served for the same team+artifact within the preceding 24 hours
 * (team/artifact/time-based, works for anonymous viewers too — spec 16's own
 * wording: "matched by team, artifact and time, not identity"). The MCP
 * correlation is checked first and wins when both match: it is the tighter,
 * higher-precision signal (minutes, same actor) and firing it never needs a
 * second write, so checking search-served only when MCP didn't match keeps
 * this a single event per view without a dedup pass across two signals.
 */
final class ArtifactViewedHandler
{
    /** Window after a search result was served in which a later view still counts as "retrieved, then opened". */
    private const SEARCH_SERVED_WINDOW_HOURS = 24;

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
        $viewerKey = $this->viewerKey($event['data']['viewer_key'] ?? null);
        $actor = $viewerUserId === null ? 'anonymous' : (string) $viewerUserId;
        $occurredAt = $this->occurredAt($event['occurred_at'] ?? null) ?? Carbon::now();

        $this->audit->record(
            AuditEventType::ArtifactViewed,
            $team,
            $actor,
            "artifact:{$artifactId}",
            'worker',
            'worker-event',
        );

        if ($viewerUserId !== null && $this->wasRetrievedViaMcp($team->id, $artifactId, $viewerUserId, $occurredAt)) {
            $this->usage->recordRetrievedThenOpened($team, $artifactId, viewerUserId: $viewerUserId, occurredAt: $occurredAt);

            return;
        }

        if ($this->wasServedViaSearch($team->id, $artifactId, $occurredAt)) {
            $this->usage->recordRetrievedThenOpened($team, $artifactId, viewerUserId: $viewerUserId, viewerKey: $viewerUserId === null ? $viewerKey : null, occurredAt: $occurredAt);

            return;
        }

        if ($viewerUserId === null) {
            $this->usage->recordAnonymousView($team, $artifactId, $viewerKey, $occurredAt);

            return;
        }

        $this->usage->recordView($team, $artifactId, $viewerUserId, $occurredAt);
    }

    private function wasRetrievedViaMcp(int $teamId, string $artifactId, int $viewerUserId, Carbon $occurredAt): bool
    {
        return McpActivity::query()
            ->where('team_id', $teamId)
            ->where('tool', 'get_artifact')
            ->where('artifact_id', $artifactId)
            ->where('actor', (string) $viewerUserId)
            ->where('outcome', 'success')
            ->where('created_at', '>=', $occurredAt->copy()->subMinutes(30))
            ->where('created_at', '<=', $occurredAt->copy()->addMinute())
            ->exists();
    }

    /**
     * A view "spends" at most one unclaimed served row — otherwise a single
     * search result would correlate every later view (reloads, unrelated
     * visitors) within the 24h window as "retrieved then opened," instead
     * of the one open the search actually explains. The select-then-update
     * is intentionally conditional (`whereNull('opened_at')` on the write,
     * not just the read): two views racing for the same served row can only
     * let one of them claim it, so this can't double-count under concurrent
     * `artifact.viewed` processing either.
     */
    private function wasServedViaSearch(int $teamId, string $artifactId, Carbon $occurredAt): bool
    {
        $candidate = SearchResultServed::query()
            ->where('team_id', $teamId)
            ->where('artifact_id', $artifactId)
            ->whereNull('opened_at')
            ->where('served_at', '>=', $occurredAt->copy()->subHours(self::SEARCH_SERVED_WINDOW_HOURS))
            ->where('served_at', '<=', $occurredAt)
            ->oldest('served_at')
            ->first();

        if ($candidate === null) {
            return false;
        }

        $claimed = SearchResultServed::query()
            ->where('id', $candidate->id)
            ->whereNull('opened_at')
            ->update(['opened_at' => $occurredAt]);

        return $claimed === 1;
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

    private function viewerKey(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
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
