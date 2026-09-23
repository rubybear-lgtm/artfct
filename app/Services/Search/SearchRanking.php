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

    /** Weight on spec 16's usage score (already decayed/weighted by `UsageScorer`). */
    private const USAGE_WEIGHT = 1.0;

    /**
     * "An artifact a team explicitly marked canonical should beat a
     * semantically closer artifact nobody has opened since it was made"
     * — larger than every other boost combined, deliberately, so
     * canonical membership dominates.
     */
    private const CANONICAL_BOOST = 2.0;

    /**
     * @param  array<int, VectorMatch>  $candidates
     * @param  array<string, float>  $usageScores  artifact id => `UsageScorer::score()`
     * @param  array<string, bool>  $canonicalArtifactIds  artifact id => true, for ids in a canonical collection
     * @return array<int, VectorMatch> sorted by combined score, descending
     */
    public static function rank(array $candidates, string $query, array $usageScores = [], array $canonicalArtifactIds = []): array
    {
        $scored = array_map(
            fn (VectorMatch $match): array => [
                'match' => $match,
                'score' => self::combinedScore($match, $query, $usageScores, $canonicalArtifactIds),
            ],
            $candidates,
        );

        usort($scored, fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_map(fn (array $entry): VectorMatch => $entry['match'], $scored);
    }

    /**
     * @param  array<string, float>  $usageScores
     * @param  array<string, bool>  $canonicalArtifactIds
     */
    public static function combinedScore(VectorMatch $match, string $query, array $usageScores = [], array $canonicalArtifactIds = []): float
    {
        $score = $match->similarity;
        $score += self::RECENCY_WEIGHT * self::recencyFactor($match->chunk->createdAt);
        $score += self::USAGE_WEIGHT * ($usageScores[$match->chunk->artifactId] ?? 0.0);

        if (self::repoMatches($query, $match->chunk->repoUrl)) {
            $score += self::REPO_MATCH_BOOST;
        }

        if ($canonicalArtifactIds[$match->chunk->artifactId] ?? false) {
            $score += self::CANONICAL_BOOST;
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
