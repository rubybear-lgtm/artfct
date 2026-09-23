<?php

namespace App\Services\Governance;

/**
 * In-memory stand-in bound in the `testing` environment. Backs every
 * retention/legal-hold/erasure Pest test without a live Worker.
 */
final class FakeArtifactGovernance implements ArtifactGovernanceContract
{
    /** @var array<string, array<string, array{id: string, created_at: string, legal_hold: bool}>> */
    private array $artifactsByOrg = [];

    /**
     * @param  array{id: string, created_at: string}  $artifact
     */
    public function seedArtifact(string $orgSlug, array $artifact): void
    {
        $this->artifactsByOrg[$orgSlug][$artifact['id']] = [
            'id' => $artifact['id'],
            'created_at' => $artifact['created_at'],
            'legal_hold' => false,
        ];
    }

    public function listArtifactsOlderThan(string $orgSlug, string $cutoffIso8601): array
    {
        return array_values(array_filter(
            $this->artifactsByOrg[$orgSlug] ?? [],
            fn (array $artifact): bool => $artifact['created_at'] < $cutoffIso8601,
        ));
    }

    public function listAllArtifacts(string $orgSlug): array
    {
        return array_values($this->artifactsByOrg[$orgSlug] ?? []);
    }

    public function hardDeleteArtifact(string $orgSlug, string $artifactId): void
    {
        $artifact = $this->artifactsByOrg[$orgSlug][$artifactId] ?? null;
        if ($artifact === null) {
            return;
        }
        if ($artifact['legal_hold']) {
            throw new ArtifactUnderLegalHoldException($artifactId);
        }
        unset($this->artifactsByOrg[$orgSlug][$artifactId]);
    }

    public function placeLegalHold(string $orgSlug, string $artifactId): void
    {
        if (isset($this->artifactsByOrg[$orgSlug][$artifactId])) {
            $this->artifactsByOrg[$orgSlug][$artifactId]['legal_hold'] = true;
        }
    }

    public function releaseLegalHold(string $orgSlug, string $artifactId): void
    {
        if (isset($this->artifactsByOrg[$orgSlug][$artifactId])) {
            $this->artifactsByOrg[$orgSlug][$artifactId]['legal_hold'] = false;
        }
    }

    public function stillExists(string $orgSlug, string $artifactId): bool
    {
        return isset($this->artifactsByOrg[$orgSlug][$artifactId]);
    }

    public function sweepOrphans(string $orgSlug): int
    {
        return 0;
    }
}
