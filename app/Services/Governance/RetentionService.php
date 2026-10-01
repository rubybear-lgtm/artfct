<?php

namespace App\Services\Governance;

use App\Enums\AuditEventType;
use App\Models\ArtifactUsageEvent;
use App\Models\SearchResultServed;
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
 * A dry run reports held artifacts separately from `toDelete`. An apply
 * aborts before deleting anything if any candidate is under legal hold.
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

        if (! $dryRun && $heldSurvivors !== []) {
            throw new ArtifactUnderLegalHoldException($heldSurvivors[0]);
        }

        if (! $dryRun) {
            foreach ($toDelete as $artifactId) {
                $this->governance->hardDeleteArtifact($team->slug, $artifactId);
                // Spec 12 DoD: "Deleting an artifact removes its vectors
                // within one processing cycle."
                $this->indexer?->removeFromIndex($team, $artifactId);
                // RUB-314: usage-signal rows (views, search-served
                // records) are per-artifact data too — they must not
                // outlive the artifact they describe.
                $this->deleteUsageSignals($team, $artifactId);
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

    private function deleteUsageSignals(Team $team, string $artifactId): void
    {
        ArtifactUsageEvent::query()->where('team_id', $team->id)->where('artifact_id', $artifactId)->delete();
        SearchResultServed::query()->where('team_id', $team->id)->where('artifact_id', $artifactId)->delete();
    }
}
