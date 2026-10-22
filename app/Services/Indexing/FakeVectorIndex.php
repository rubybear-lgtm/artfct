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

    public function query(string $orgId, array $queryVector, int $limit): array
    {
        $matches = array_map(
            fn (VectorChunk $chunk): VectorMatch => new VectorMatch(
                $chunk,
                CosineSimilarity::between($queryVector, $chunk->vector),
            ),
            $this->allVectorsForOrg($orgId),
        );

        usort($matches, fn (VectorMatch $a, VectorMatch $b): int => $b->similarity <=> $a->similarity);

        return array_slice($matches, 0, $limit);
    }

    public function queryText(string $orgId, string $query, int $limit): array
    {
        $terms = preg_split('/\s+/', mb_strtolower(trim($query)), flags: PREG_SPLIT_NO_EMPTY);
        if ($terms === []) {
            return [];
        }
        $matches = [];
        foreach ($this->allVectorsForOrg($orgId) as $chunk) {
            $text = mb_strtolower(implode(' ', [$chunk->text, $chunk->agent, $chunk->repoUrl, $chunk->commitSha]));
            if (array_all($terms, fn (string $term): bool => str_contains($text, $term))) {
                $matches[] = new VectorMatch($chunk, 1.0);
            }
        }

        return array_slice($matches, 0, $limit);
    }

    public function queryArtifacts(string $orgId, array $queryVector, int $limit, ?array $artifactIds = null): array
    {
        return $this->distinctArtifacts($this->query($orgId, $queryVector, count($this->allVectorsForOrg($orgId))), $limit, $artifactIds);
    }

    public function queryTextArtifacts(string $orgId, string $query, int $limit, ?array $artifactIds = null): array
    {
        return $this->distinctArtifacts($this->queryText($orgId, $query, count($this->allVectorsForOrg($orgId))), $limit, $artifactIds);
    }

    /**
     * @param  list<VectorMatch>  $matches
     * @param  list<string>|null  $artifactIds
     * @return list<VectorMatch>
     */
    private function distinctArtifacts(array $matches, int $limit, ?array $artifactIds): array
    {
        if ($artifactIds === []) {
            return [];
        }

        $allowed = $artifactIds === null ? null : array_flip($artifactIds);
        $seen = [];
        foreach ($matches as $match) {
            $id = $match->chunk->artifactId;
            if (($allowed === null || isset($allowed[$id])) && ! isset($seen[$id])) {
                $seen[$id] = $match;
            }
        }

        return array_slice(array_values($seen), 0, max(0, $limit));
    }

    public function firstChunks(string $orgId, array $artifactIds, int $perArtifact): array
    {
        $chunks = [];
        foreach ($artifactIds as $id) {
            array_push($chunks, ...array_slice($this->byOrg[$orgId][$id] ?? [], 0, max(0, $perArtifact)));
        }

        return $chunks;
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
