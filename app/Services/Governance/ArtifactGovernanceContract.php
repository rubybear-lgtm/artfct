<?php

namespace App\Services\Governance;

/**
 * The seam between Laravel-orchestrated governance (retention, legal hold,
 * GDPR erasure) and the artifact data those actions act on — which lives in
 * the Worker's D1/R2, not Laravel's database. Same shape as spec 09's
 * `TenantProvisionerContract`: an injectable interface, a `Real*`
 * implementation that fails closed, and a `Fake*` bound in `testing`.
 */
interface ArtifactGovernanceContract
{
    /**
     * Every artifact in `$orgSlug` created before `$cutoffIso8601`, with its
     * legal-hold status. The retention job's dry run and the GDPR erasure
     * plan both call this — neither deletes anything by calling it.
     *
     * @return array<int, array{id: string, created_at: string, legal_hold: bool}>
     */
    public function listArtifactsOlderThan(string $orgSlug, string $cutoffIso8601): array;

    /**
     * Every artifact in `$orgSlug`, with legal-hold status — the erasure
     * candidate set (erasure has no age cutoff; it targets every artifact
     * referencing the subject's data in the org).
     *
     * @return array<int, array{id: string, created_at: string, legal_hold: bool}>
     */
    public function listAllArtifacts(string $orgSlug): array;

    /**
     * Hard-deletes one artifact, decrementing its blob's refcount.
     *
     * @throws ArtifactUnderLegalHoldException when the artifact is held.
     */
    public function hardDeleteArtifact(string $orgSlug, string $artifactId): void;

    public function placeLegalHold(string $orgSlug, string $artifactId): void;

    public function releaseLegalHold(string $orgSlug, string $artifactId): void;
}
