<?php

namespace App\Services\Collections;

use App\Enums\UsageEventType;
use App\Models\ArtifactUsageEvent;
use App\Models\Team;
use Illuminate\Support\Carbon;

/**
 * Records one usage-signal occurrence (spec 16). Real, tested, and wired
 * into `SlackPostService` (a Slack share is exactly the "someone vouched
 * for it to colleagues" signal). The Worker-side `/p/{id}` view hook and
 * MCP-side "retrieved by an agent, then the artifact opened" correlation
 * are not wired in this environment — the same Worker/Laravel boundary
 * gap named in DOCUMENTATION.md's "the recurring gap" section (specs
 * 11/12/14); `usage:record` is the manual/ops entry point in the
 * meantime, exercising the identical write path.
 */
final class UsageEventLogger
{
    public function recordView(Team $team, string $artifactId, int $viewerUserId, ?Carbon $occurredAt = null): void
    {
        $this->record($team, $artifactId, UsageEventType::Viewed, $viewerUserId, occurredAt: $occurredAt);
    }

    public function recordAnonymousView(Team $team, string $artifactId, ?Carbon $occurredAt = null): void
    {
        $this->record($team, $artifactId, UsageEventType::Viewed, null, occurredAt: $occurredAt);
    }

    public function recordSlackShare(Team $team, string $artifactId, ?int $actorUserId = null, ?Carbon $occurredAt = null): void
    {
        $this->record($team, $artifactId, UsageEventType::SlackShared, $actorUserId, occurredAt: $occurredAt);
    }

    public function recordRetrievedThenOpened(Team $team, string $artifactId, int $viewerUserId, ?Carbon $occurredAt = null): void
    {
        $this->record($team, $artifactId, UsageEventType::RetrievedThenOpened, $viewerUserId, occurredAt: $occurredAt);
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
    ): void {
        ArtifactUsageEvent::create([
            'team_id' => $team->id,
            'artifact_id' => $artifactId,
            'event_type' => $type,
            'actor_user_id' => $actorUserId,
            'related_artifact_id' => $relatedArtifactId,
            'occurred_at' => $occurredAt ?? Carbon::now(),
        ]);
    }
}
