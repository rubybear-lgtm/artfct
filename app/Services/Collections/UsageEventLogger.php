<?php

namespace App\Services\Collections;

use App\Enums\UsageEventType;
use App\Models\ArtifactUsageEvent;
use App\Models\Team;
use Illuminate\Support\Carbon;

/**
 * Records one usage-signal occurrence (spec 16). Real, tested, and wired
 * into `SlackPostService` (a Slack share is exactly the "someone vouched
 * for it to colleagues" signal), the Worker-side `/p/{id}` view hook
 * (`ArtifactViewedHandler`, fed by `artifact.viewed` worker events), and
 * the MCP-side "retrieved by an agent, then the artifact opened" and
 * search-served "retrieved then opened" correlations (RUB-314). `viewer_key`
 * carries the anonymous-view path: a daily-salted pseudonymous key the
 * Worker derives when no verified `viewer_user_id` is available (Slack
 * opens, shared links — the majority of `/p/{id}` traffic), so distinct
 * anonymous viewers still count toward `UsageScorer`'s ranking.
 */
final class UsageEventLogger
{
    public function recordView(Team $team, string $artifactId, int $viewerUserId, ?Carbon $occurredAt = null): void
    {
        $this->record($team, $artifactId, UsageEventType::Viewed, $viewerUserId, occurredAt: $occurredAt);
    }

    public function recordAnonymousView(Team $team, string $artifactId, ?string $viewerKey, ?Carbon $occurredAt = null): void
    {
        $this->record($team, $artifactId, UsageEventType::Viewed, null, viewerKey: $viewerKey, occurredAt: $occurredAt);
    }

    public function recordSlackShare(Team $team, string $artifactId, ?int $actorUserId = null, ?Carbon $occurredAt = null): void
    {
        $this->record($team, $artifactId, UsageEventType::SlackShared, $actorUserId, occurredAt: $occurredAt);
    }

    /**
     * `$viewerUserId` for the MCP-retrieval correlation (identified
     * viewers); `$viewerKey` for the search-served correlation on an
     * anonymous viewer. At most one of the two is ever set by callers —
     * `ArtifactViewedHandler` picks exactly one correlation per view.
     */
    public function recordRetrievedThenOpened(Team $team, string $artifactId, ?int $viewerUserId = null, ?string $viewerKey = null, ?Carbon $occurredAt = null): void
    {
        $this->record($team, $artifactId, UsageEventType::RetrievedThenOpened, $viewerUserId, viewerKey: $viewerKey, occurredAt: $occurredAt);
    }

    public function recordSuperseded(Team $team, string $oldArtifactId, string $newArtifactId, ?Carbon $occurredAt = null): void
    {
        $this->record($team, $oldArtifactId, UsageEventType::Superseded, null, $newArtifactId, $occurredAt);
    }

    private function record(
        Team $team,
        string $artifactId,
        UsageEventType $type,
        ?int $actorUserId,
        ?string $relatedArtifactId = null,
        ?Carbon $occurredAt = null,
        ?string $viewerKey = null,
    ): void {
        $attributes = [
            'team_id' => $team->id,
            'artifact_id' => $artifactId,
            'event_type' => $type,
            'actor_user_id' => $actorUserId,
            'viewer_key' => $viewerKey,
            'related_artifact_id' => $relatedArtifactId,
            'occurred_at' => $occurredAt ?? Carbon::now(),
        ];

        if ($viewerKey === null) {
            ArtifactUsageEvent::create($attributes);

            return;
        }

        // Anonymous events are deduped Laravel-side on
        // (team, artifact, viewer_key, event_type) — see the migration
        // that adds `viewer_key` for why this alone gives "one event per
        // visitor per artifact per day" without a separate date column,
        // and why this is idempotent against `artifact.viewed` redelivery
        // rather than a Worker-side skip. `createOrFirst` (not
        // `firstOrCreate`) because it inserts first and falls back to the
        // existing row only on a unique-constraint violation, so two
        // concurrent queue workers processing the same visitor's views
        // can't both pass a select and then race each other into the
        // unique index.
        ArtifactUsageEvent::query()->createOrFirst(
            [
                'team_id' => $team->id,
                'artifact_id' => $artifactId,
                'viewer_key' => $viewerKey,
                'event_type' => $type,
            ],
            $attributes,
        );
    }
}
