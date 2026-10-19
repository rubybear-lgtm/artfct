<?php

namespace App\Services\Search;

use App\Services\Indexing\VectorMatch;
use Illuminate\Support\Carbon;

/**
 * Rank by raw reranker relevance bands before applying recency, provenance,
 * usage or canonical signals. Existing boosts break ties among near matches;
 * a materially less relevant document cannot win through popularity alone.
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
     * — applied only within a relevance band, after calibrated
     * no-match filtering; canonical membership cannot revive irrelevant work.
     */
    private const CANONICAL_BOOST = 2.0;

    /**
     * @param  array<int, VectorMatch>  $candidates
     * @param  array<string, float>  $usageScores  artifact id => `UsageScorer::score()`
     * @param  array<string, bool>  $canonicalArtifactIds  artifact id => true, for ids in a canonical collection
     * @return array<int, VectorMatch> relevance bands first; existing boosts only order near matches within a 5% relative band
     */
    public static function rank(array $candidates, string $query, array $usageScores = [], array $canonicalArtifactIds = []): array
    {
        $scored = array_map(
            fn (VectorMatch $match): array => [
                'match' => $match,
                'score' => self::combinedScore($match, $query, $usageScores, $canonicalArtifactIds),
                'relevance_band' => (int) floor(log(max(0.000001, $match->similarity)) / log(1.05)),
            ],
            $candidates,
        );

        usort($scored, fn (array $a, array $b): int => ($b['relevance_band'] <=> $a['relevance_band']) ?: ($b['score'] <=> $a['score']));

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
