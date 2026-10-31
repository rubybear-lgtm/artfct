<?php

namespace App\Services\Search;

use App\Services\Indexing\VectorMatch;
use Illuminate\Support\Carbon;

/**
 * Rank by title-match tier, then raw reranker relevance bands, before applying
 * recency, provenance, usage or canonical signals. Exact and near-exact titles
 * (case-insensitive) beat semantic near-misses so a publish-time title search
 * returns that artifact first. Existing boosts only order near matches within
 * a 5% relative band among the same title tier.
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

    private const TITLE_MATCH_NONE = 0;

    private const TITLE_MATCH_NEAR = 1;

    private const TITLE_MATCH_EXACT = 2;

    /**
     * @param  array<int, VectorMatch>  $candidates
     * @param  array<string, float>  $usageScores  artifact id => `UsageScorer::score()`
     * @param  array<string, bool>  $canonicalArtifactIds  artifact id => true, for ids in a canonical collection
     * @param  array<string, string|null>  $titles  artifact id => title from the directory
     * @return array<int, VectorMatch> exact/near-exact title first; then relevance bands; boosts only order near matches within a 5% relative band
     */
    public static function rank(array $candidates, string $query, array $usageScores = [], array $canonicalArtifactIds = [], array $titles = []): array
    {
        $scored = array_map(
            fn (VectorMatch $match): array => [
                'match' => $match,
                'title_tier' => self::titleMatchTier($query, $titles[$match->chunk->artifactId] ?? null),
                'score' => self::combinedScore($match, $query, $usageScores, $canonicalArtifactIds),
                'relevance_band' => (int) floor(log(max(0.000001, $match->similarity)) / log(1.05)),
            ],
            $candidates,
        );

        usort(
            $scored,
            fn (array $a, array $b): int => ($b['title_tier'] <=> $a['title_tier'])
                ?: ($b['relevance_band'] <=> $a['relevance_band'])
                ?: ($b['score'] <=> $a['score']),
        );

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

    /**
     * Score shown to MCP/API callers — and the value results are ordered by.
     *
     * Title-match tiers occupy disjoint bands so an exact title always outranks
     * a higher raw similarity miss by ≥0.05 (RUB-515/RUB-516). Within a band,
     * similarity still orders near matches.
     */
    public static function displayScore(float $similarity, string $query, ?string $title): float
    {
        $similarity = max(0.0, min(1.0, $similarity));
        $tier = self::titleMatchTier($query, $title);

        // Exact: 0.92–1.00, near: 0.80–0.91, none: 0.00–0.79.
        $score = match ($tier) {
            self::TITLE_MATCH_EXACT => 0.92 + (0.08 * $similarity),
            self::TITLE_MATCH_NEAR => 0.80 + (0.11 * $similarity),
            default => 0.79 * $similarity,
        };

        return round(min(1.0, $score), 6);
    }

    /**
     * Exact (case-insensitive trim) outranks near-exact (punctuation/whitespace
     * normalized, or ≥90% similar_text on close lengths). Both outrank a
     * semantic-only hit so usage and canonical boosts cannot bury a title match.
     */
    public static function titleMatchTier(string $query, ?string $title): int
    {
        $query = trim($query);
        $title = trim((string) $title);
        if ($query === '' || $title === '') {
            return self::TITLE_MATCH_NONE;
        }

        if (mb_strtolower($query) === mb_strtolower($title)) {
            return self::TITLE_MATCH_EXACT;
        }

        $normalizedQuery = self::normalizeTitle($query);
        $normalizedTitle = self::normalizeTitle($title);
        if ($normalizedQuery === '' || $normalizedTitle === '') {
            return self::TITLE_MATCH_NONE;
        }

        if ($normalizedQuery === $normalizedTitle) {
            return self::TITLE_MATCH_NEAR;
        }

        $queryLength = mb_strlen($normalizedQuery);
        $titleLength = mb_strlen($normalizedTitle);
        $maxLength = max($queryLength, $titleLength);
        $minLength = min($queryLength, $titleLength);
        if (($minLength / $maxLength) < 0.85) {
            return self::TITLE_MATCH_NONE;
        }

        similar_text($normalizedQuery, $normalizedTitle, $percent);

        return $percent >= 90.0 ? self::TITLE_MATCH_NEAR : self::TITLE_MATCH_NONE;
    }

    private static function normalizeTitle(string $value): string
    {
        $value = mb_strtolower($value);
        $value = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }
}
