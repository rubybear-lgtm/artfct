<?php

namespace App\Services\Governance;

use App\Enums\AuditEventType;
use App\Models\Team;
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
