<?php

namespace App\Services\Indexing;

/**
 * Cross-encoder reranking (RUB-316, 2026-09-29 architecture decision — day
 * one, not a fast-follow). Takes the query and a small candidate set
 * (already fused from vector + full-text search) and returns them
 * reordered by direct query/document relevance — the single highest-
 * leverage step for precision on agent-consumed retrieval.
 */
interface RerankerContract
{
    /**
     * @param  array<int, VectorMatch>  $candidates
     * @return array<int, VectorMatch> the same candidates, reordered by
     *                                 relevance, with `similarity` replaced
     *                                 by the reranker's score
     */
    public function rerank(string $query, array $candidates): array;
}
