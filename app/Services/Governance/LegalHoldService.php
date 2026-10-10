<?php

namespace App\Services\Governance;

use App\Enums\AuditEventType;
use App\Models\ArtifactUsageEvent;
use App\Models\SearchResultServed;
use App\Models\Team;
use App\Services\Billing\PlanGate;
use App\Services\Indexing\IndexingService;

final class LegalHoldService
{
    public function __construct(
        private readonly ArtifactGovernanceContract $governance,
        private readonly AuditLogger $auditLogger,
        private readonly ?IndexingService $indexer = null,
    ) {}

    public function place(Team $team, string $artifactId, string $actor): void
    {
        PlanGate::requireEnterprise($team, 'Legal hold');

        $this->governance->placeLegalHold($team->slug, $artifactId);
        $this->auditLogger->record(
            AuditEventType::LegalHoldApplied,
            $team,
            $actor,
            $artifactId,
            'internal',
            'governance:legal-hold',
            'placed',
        );
    }

    public function release(Team $team, string $artifactId, string $actor): void
    {
        $this->governance->releaseLegalHold($team->slug, $artifactId);
        $this->auditLogger->record(
            AuditEventType::LegalHoldApplied,
            $team,
            $actor,
            $artifactId,
            'internal',
            'governance:legal-hold',
            'released',
        );
    }

    /**
     * Attempts a hard delete, surfacing a refusal rather than throwing past
     * the caller uncaught — DoD: "refused and audited."
     */
    public function attemptHardDelete(Team $team, string $artifactId, string $actor): bool
    {
        try {
            $this->governance->hardDeleteArtifact($team->slug, $artifactId);
        } catch (ArtifactUnderLegalHoldException $exception) {
            $this->auditLogger->record(
                AuditEventType::ArtifactDeleted,
                $team,
                $actor,
                $artifactId,
                'internal',
                'governance:hard-delete',
                "refused: {$exception->getMessage()}",
            );

            return false;
        }

        $this->indexer?->removeFromIndex($team, $artifactId);
        // RUB-314: same per-artifact usage-signal cleanup as retention and
        // erasure — a direct hard delete must not leave them behind either.
        ArtifactUsageEvent::query()->where('team_id', $team->id)->where('artifact_id', $artifactId)->delete();
        SearchResultServed::query()->where('team_id', $team->id)->where('artifact_id', $artifactId)->delete();

        $this->auditLogger->record(
            AuditEventType::ArtifactDeleted,
            $team,
            $actor,
            $artifactId,
            'internal',
            'governance:hard-delete',
            'deleted',
        );

        return true;
    }
}
