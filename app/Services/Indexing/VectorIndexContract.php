<?php

namespace App\Services\Indexing;

/**
 * One Vectorize index per tenant (spec 12: "the isolation boundary is the
 * same as everywhere else, and a shared index with metadata filtering is
 * one bug away from cross-tenant leakage") — every method here is
 * `$orgId`-scoped, and an implementation must never let one org's upsert
 * become visible to another org's query.
 */
interface VectorIndexContract
{
    /**
     * @param  array<int, VectorChunk>  $chunks
     */
    public function upsertChunks(string $orgId, string $artifactId, array $chunks): void;

    /**
     * Removes every vector belonging to one artifact — DoD: "Deleting an
     * artifact removes its vectors within one processing cycle."
     */
    public function deleteArtifactVectors(string $orgId, string $artifactId): void;

    /**
     * Every vector currently stored for one org — direct inspection, for
     * the isolation test (`tenant_index_contains_no_foreign_vectors`) and
     * nothing else; a real Vectorize deployment wouldn't expose a "dump
     * everything" call outside test tooling.
     *
     * @return array<int, VectorChunk>
     */
    public function allVectorsForOrg(string $orgId): array;
}
