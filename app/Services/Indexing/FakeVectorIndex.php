<?php

namespace App\Services\Indexing;

/**
 * In-memory per-tenant index, bound in `testing`. Keyed at the top level by
 * `$orgId` so a bug that leaked cross-tenant data would have to reach
 * across a different array key entirely — the shape the isolation test
 * exercises directly.
 */
final class FakeVectorIndex implements VectorIndexContract
{
    /** @var array<string, array<string, array<int, VectorChunk>>> orgId => artifactId => chunks */
    private array $byOrg = [];

    public function upsertChunks(string $orgId, string $artifactId, array $chunks): void
    {
        $this->byOrg[$orgId][$artifactId] = $chunks;
    }

    public function deleteArtifactVectors(string $orgId, string $artifactId): void
    {
        unset($this->byOrg[$orgId][$artifactId]);
    }

    public function allVectorsForOrg(string $orgId): array
    {
        return array_merge(...array_values($this->byOrg[$orgId] ?? [[]]));
    }

    public function artifactHasVectors(string $orgId, string $artifactId): bool
    {
        return ! empty($this->byOrg[$orgId][$artifactId] ?? []);
    }
}
