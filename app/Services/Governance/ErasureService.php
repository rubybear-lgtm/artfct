<?php

namespace App\Services\Governance;

use App\Enums\AuditEventType;
use App\Models\Team;

/**
 * GDPR erasure across an org (spec 11): forces hard deletion of every
 * artifact referencing the subject's data in the org, and is refused —
 * whole, never partially — while any of them is under legal hold. The
 * conflict is always named explicitly, per DoD: "refused explicitly, with
 * the conflict named, never silently partial."
 */
final class ErasureService
{
    public function __construct(
        private readonly ArtifactGovernanceContract $governance,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function erase(Team $team, bool $dryRun, string $actor = 'system'): ErasurePlan
    {
        $candidates = $this->governance->listAllArtifacts($team->slug);

        $held = array_values(array_filter($candidates, fn (array $c): bool => $c['legal_hold']));
        if ($held !== []) {
            $heldId = $held[0]['id'];

            $this->auditLogger->record(
                AuditEventType::ArtifactDeleted,
                $team,
                $actor,
                $team->slug,
                'internal',
                'governance:erase',
                "refused: artifact [{$heldId}] is under legal hold",
            );

            return ErasurePlan::refused($heldId, $dryRun);
        }

        $ids = array_map(fn (array $c): string => $c['id'], $candidates);

        if (! $dryRun) {
            foreach ($ids as $artifactId) {
                $this->governance->hardDeleteArtifact($team->slug, $artifactId);
            }
        }

        $this->auditLogger->record(
            AuditEventType::ArtifactDeleted,
            $team,
            $actor,
            $team->slug,
            'internal',
            'governance:erase',
            $dryRun
                ? sprintf('dry_run: would erase %d artifact(s)', count($ids))
                : sprintf('erased %d artifact(s)', count($ids)),
        );

        return ErasurePlan::proceed($ids, $dryRun);
    }
}
