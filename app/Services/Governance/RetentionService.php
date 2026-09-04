<?php

namespace App\Services\Governance;

use App\Enums\AuditEventType;
use App\Models\Team;
use App\Services\Indexing\IndexingService;
use Carbon\CarbonImmutable;

/**
 * Applies a team's retention policy (spec 11). `apply(..., dryRun: true)`
 * is the primary, safer path — it computes and audits exactly what a real
 * run would do without deleting anything, matching the Rollback section's
 * "every destructive path is dry-runnable first, and the dry run is itself
 * part of the DoD for operating it." `dryRun: false` executes it.
 *
 * Held artifacts always survive (DoD: "An artifact under legal hold
 * survives the retention job") — they are reported separately from
 * `toDelete`, never attempted.
 */
final class RetentionService
{
    public function __construct(
        private readonly ArtifactGovernanceContract $governance,
        private readonly AuditLogger $auditLogger,
        private readonly ?IndexingService $indexer = null,
    ) {}

    public function apply(Team $team, ?int $retentionDays, bool $dryRun, string $actor = 'system'): RetentionPlan
    {
        $days = $retentionDays ?? $team->retention_days ?? self::defaultRetentionDays();
        $cutoff = CarbonImmutable::now()->subDays($days)->toIso8601String();

        $candidates = $this->governance->listArtifactsOlderThan($team->slug, $cutoff);

        $toDelete = [];
        $heldSurvivors = [];
        foreach ($candidates as $candidate) {
            if ($candidate['legal_hold']) {
                $heldSurvivors[] = $candidate['id'];

                continue;
            }
            $toDelete[] = $candidate['id'];
        }

        if (! $dryRun) {
            foreach ($toDelete as $artifactId) {
                $this->governance->hardDeleteArtifact($team->slug, $artifactId);
                // Spec 12 DoD: "Deleting an artifact removes its vectors
                // within one processing cycle."
                $this->indexer?->removeFromIndex($team, $artifactId);
            }
        }

        $this->auditLogger->record(
            AuditEventType::RetentionApplied,
            $team,
            $actor,
            $team->slug,
            'internal',
            'governance:retention',
            $dryRun
                ? sprintf('dry_run: %d candidate(s), %d held survivor(s)', count($toDelete), count($heldSurvivors))
                : sprintf('deleted %d artifact(s), %d held survivor(s)', count($toDelete), count($heldSurvivors)),
        );

        return new RetentionPlan($toDelete, $heldSurvivors, $dryRun);
    }

    public static function defaultRetentionDays(): int
    {
        return (int) config('governance.default_retention_days', 90);
    }
}
