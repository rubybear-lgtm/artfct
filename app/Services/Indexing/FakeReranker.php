<?php

namespace App\Services\Indexing;

/**
 * Deterministic stand-in for the real cross-encoder: an exact (case-
 * insensitive) substring match of the query in the chunk's text scores
 * above everything else, tied-broken by the incoming similarity. Real
 * enough to prove "reranking changed the order" in tests without needing
 * a live model.
 */
final class FakeReranker implements RerankerContract
{
    public function rerank(string $query, array $candidates): array
    {
        $needle = mb_strtolower($query);

        $scored = array_map(function (VectorMatch $match) use ($needle): VectorMatch {
            $exactMatch = $needle !== '' && str_contains(mb_strtolower($match->chunk->text), $needle);

            return new VectorMatch($match->chunk, $exactMatch ? 1.0 + $match->similarity : $match->similarity);
        }, $candidates);

        usort($scored, fn (VectorMatch $a, VectorMatch $b): int => $b->similarity <=> $a->similarity);

        return $scored;
    }
}
