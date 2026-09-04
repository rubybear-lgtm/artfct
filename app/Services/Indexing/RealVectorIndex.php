<?php

namespace App\Services\Indexing;

use RuntimeException;

final class RealVectorIndex implements VectorIndexContract
{
    public function upsertChunks(string $orgId, string $artifactId, array $chunks): void
    {
        $this->requireConfigured();
        throw new RuntimeException('RealVectorIndex::upsertChunks is not implemented — no live Vectorize account in this environment.');
    }

    public function deleteArtifactVectors(string $orgId, string $artifactId): void
    {
        $this->requireConfigured();
        throw new RuntimeException('RealVectorIndex::deleteArtifactVectors is not implemented — no live Vectorize account in this environment.');
    }

    public function allVectorsForOrg(string $orgId): array
    {
        $this->requireConfigured();
        throw new RuntimeException('RealVectorIndex::allVectorsForOrg is not implemented — no live Vectorize account in this environment.');
    }

    private function requireConfigured(): void
    {
        if (! config('services.cloudflare.vectorize_account_id')) {
            throw new RuntimeException('services.cloudflare.vectorize_account_id must be configured to use the vector index.');
        }
    }
}
