<?php

namespace App\Services\Governance;

use RuntimeException;

/**
 * Real Cloudflare-side governance actions. Fails closed, same pattern as
 * `App\Services\Tenancy\RealTenantProvisioner`: the Worker's D1R2 store has
 * the pure decision logic this depends on (`backend/src/governance.rs`,
 * `MemoryArtifactStore::hard_delete`/`place_legal_hold`) and the D1 schema
 * already has the `legal_hold`/`retention_class` columns, but the HTTP
 * routes exposing hard-delete/legal-hold/list-older-than to Laravel are not
 * wired into the Worker's request dispatch in this environment — the same
 * gap spec 9 left for `RealTenantProvisioner`, for the same reason (no live
 * account to build and verify the wiring against end to end).
 */
final class RealArtifactGovernance implements ArtifactGovernanceContract
{
    public function listArtifactsOlderThan(string $orgSlug, string $cutoffIso8601): array
    {
        $this->requireConfigured();
        throw new RuntimeException('RealArtifactGovernance::listArtifactsOlderThan is not implemented — the Worker has no live governance HTTP routes in this environment.');
    }

    public function listAllArtifacts(string $orgSlug): array
    {
        $this->requireConfigured();
        throw new RuntimeException('RealArtifactGovernance::listAllArtifacts is not implemented — the Worker has no live governance HTTP routes in this environment.');
    }

    public function hardDeleteArtifact(string $orgSlug, string $artifactId): void
    {
        $this->requireConfigured();
        throw new RuntimeException('RealArtifactGovernance::hardDeleteArtifact is not implemented — the Worker has no live governance HTTP routes in this environment.');
    }

    public function placeLegalHold(string $orgSlug, string $artifactId): void
    {
        $this->requireConfigured();
        throw new RuntimeException('RealArtifactGovernance::placeLegalHold is not implemented — the Worker has no live governance HTTP routes in this environment.');
    }

    public function releaseLegalHold(string $orgSlug, string $artifactId): void
    {
        $this->requireConfigured();
        throw new RuntimeException('RealArtifactGovernance::releaseLegalHold is not implemented — the Worker has no live governance HTTP routes in this environment.');
    }

    private function requireConfigured(): void
    {
        if (! config('services.worker.base_url')) {
            throw new RuntimeException('services.worker.base_url must be configured to perform governance actions.');
        }
    }
}
