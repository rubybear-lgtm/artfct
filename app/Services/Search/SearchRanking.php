<?php

namespace App\Services\Search;

use App\Services\Indexing\VectorMatch;
use Illuminate\Support\Carbon;

/**
 * Pure hybrid ranking (spec 13: "semantic similarity plus recency plus
 * provenance match"). No I/O, no embeddings, no vector-index calls — takes
 * candidates already fetched and orders them, so the ranking rule itself
 * (in particular "an exact repo match should outrank a semantically closer
 * artifact from an unrelated repo") is unit-testable independent of any
 * real or fake embedding model.
 */
final class SearchRanking
{
    private const REPO_MATCH_BOOST = 0.5;

    private const RECENCY_WEIGHT = 0.05;

    /**
     * @param  array<int, VectorMatch>  $candidates
     * @return array<int, VectorMatch> sorted by combined score, descending
     */
    public static function rank(array $candidates, string $query): array
    {
        $scored = array_map(
            fn (VectorMatch $match): array => [
                'match' => $match,
                'score' => self::combinedScore($match, $query),
            ],
            $candidates,
        );

        usort($scored, fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_map(fn (array $entry): VectorMatch => $entry['match'], $scored);
    }

    public static function combinedScore(VectorMatch $match, string $query): float
    {
        $score = $match->similarity;
        $score += self::RECENCY_WEIGHT * self::recencyFactor($match->chunk->createdAt);

        if (self::repoMatches($query, $match->chunk->repoUrl)) {
            $score += self::REPO_MATCH_BOOST;
        }

        return $score;
    }

    /**
     * 1.0 for something created right now, decaying toward 0 as it ages —
     * "recency" as a small tie-breaking signal, not a dominant one.
     */
    private static function recencyFactor(string $createdAtIso8601): float
    {
        $ageDays = max(0, Carbon::now()->diffInDays(Carbon::parse($createdAtIso8601), absolute: true));

        return 1.0 / (1.0 + $ageDays);
    }

    /**
     * Whether the query text names the artifact's repo — "the billing
     * dashboard" matching a repo whose last path segment is "billing".
     * Deliberately a simple whole-word substring match, not a further
     * embedding call.
     */
    private static function repoMatches(string $query, ?string $repoUrl): bool
    {
        if ($repoUrl === null || $repoUrl === '') {
            return false;
        }

        $repoName = collect(explode('/', rtrim($repoUrl, '/')))->last();
        if (! is_string($repoName) || $repoName === '') {
            return false;
        }

        return (bool) preg_match('/\b'.preg_quote($repoName, '/').'\b/i', $query);
    }
}
