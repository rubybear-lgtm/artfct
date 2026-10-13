<?php

namespace App\Services\Search;

use App\Contracts\ArtifactDirectory;
use App\Enums\AuditEventType;
use App\Models\ArtifactUsageEvent;
use App\Models\Collection;
use App\Models\SearchResultServed;
use App\Models\Team;
use App\Services\Artifacts\ArtifactViewLink;
use App\Services\Collections\UsageScorer;
use App\Services\Governance\AuditLogger;
use App\Services\Indexing\EmbeddingsContract;
use App\Services\Indexing\RerankerContract;
use App\Services\Indexing\VectorIndexContract;
use App\Services\Indexing\VectorMatch;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

/**
 * `search_artifacts` (spec 13, extended by spec 16): hybrid semantic +
 * recency + provenance + usage + canonical-collection ranking, strictly
 * scoped to the caller's org, excluding revoked/inaccessible artifacts,
 * auditing every search.
 */
final class SearchService
{
    public function __construct(
        private readonly EmbeddingsContract $embeddings,
        private readonly VectorIndexContract $vectorIndex,
        private readonly ArtifactDirectory $artifacts,
        private readonly AuditLogger $auditLogger,
        private readonly RerankerContract $reranker,
    ) {}

    /**
     * @param  array{repo?: ?string, agent?: ?string, since?: ?string, collection?: ?string}  $filters
     * @return array<int, SearchResult>
     */
    public function search(
        Team $team,
        string $query,
        array $filters,
        int $limit,
        string $actor,
        string $ip = 'internal',
        string $userAgent = 'search',
    ): array {
        $directory = $this->artifactsBySlug($team);

        // spec 16: `collection: "reporting-formats"` scopes results to
        // exactly that collection's members — resolved before ranking so
        // an out-of-collection artifact never even reaches the scorer.
        $collectionArtifactIds = null;
        if (! empty($filters['collection'])) {
            $collection = Collection::query()->where('team_id', $team->id)->where('name', $filters['collection'])->first();
            $collectionArtifactIds = $collection === null ? [] : array_flip($collection->artifactIds());
        }

        $isAllowed = function (string $id) use ($directory, $filters, $collectionArtifactIds): bool {
            $artifact = $directory[$id] ?? null;
            if ($artifact === null || $artifact['revoked_at'] !== null) {
                return false;
            }
            if ($collectionArtifactIds !== null && ! isset($collectionArtifactIds[$id])) {
                return false;
            }
            if (! empty($filters['repo']) && ($artifact['provenance']['repo_url'] ?? null) !== $filters['repo']) {
                return false;
            }
            if (! empty($filters['agent']) && ($artifact['provenance']['agent'] ?? null) !== $filters['agent']) {
                return false;
            }
            if (! empty($filters['since']) && CarbonImmutable::parse($artifact['created_at'])->lt(CarbonImmutable::parse($filters['since']))) {
                return false;
            }

            return true;
        };
        $allowedIds = array_values(array_filter(array_map(strval(...), array_keys($directory)), $isAllowed));
        $queryVector = $this->embeddings->embedQuery($query);
        $candidateLimit = max($limit * 5, 50);
        $vectorCandidates = $this->vectorIndex->queryArtifacts($team->slug, $queryVector, $candidateLimit, $allowedIds);
        $lexicalCandidates = $this->vectorIndex->queryTextArtifacts($team->slug, $query, $candidateLimit, $allowedIds);
        $candidates = ReciprocalRankFusion::combine($vectorCandidates, $lexicalCandidates);
        $filtered = array_values(array_filter($candidates, fn (VectorMatch $match): bool => $match->chunk->orgId === $team->slug && $isAllowed($match->chunk->artifactId)));

        $filtered = array_slice($filtered, 0, max(20, $limit));
        $introductions = $this->vectorIndex->firstChunks($team->slug, array_map(fn (VectorMatch $match): string => $match->chunk->artifactId, $filtered), 2);
        $passages = SearchPassageSelection::expand($filtered, $introductions, [...$vectorCandidates, ...$lexicalCandidates], $query, array_map(fn (array $artifact): ?string => $artifact['title'] ?? null, $directory));
        $filtered = SearchPassageSelection::bestArtifacts($this->reranker->rerank($query, $passages), (float) config('indexing.minimum_search_relevance', 0.05), $query);

        $usageScores = $this->usageScoresFor($team, $filtered);
        $canonicalArtifactIds = $this->canonicalArtifactIdsFor($team);

        $ranked = SearchRanking::rank($filtered, $query, $usageScores, $canonicalArtifactIds);
        $top = array_slice($ranked, 0, $limit);

        $results = array_map(
            fn (VectorMatch $match): SearchResult => $this->toResult($team, $match, $directory[$match->chunk->artifactId], $query),
            $top,
        );

        $this->auditLogger->record(
            AuditEventType::SearchPerformed,
            $team,
            $actor,
            $query,
            $ip,
            $userAgent,
            sprintf('%d result(s)', count($results)),
        );

        $this->recordResultsServed($team, $results);

        return $results;
    }

    /**
     * One `search_results_served` row per returned artifact (RUB-314) — the
     * raw material `ArtifactViewedHandler` matches a later view against, by
     * team + artifact + time, to record a "retrieved, then opened" usage
     * event without needing to know who searched or who later opened it.
     *
     * @param  array<int, SearchResult>  $results
     */
    private function recordResultsServed(Team $team, array $results): void
    {
        if ($results === []) {
            return;
        }

        $servedAt = Carbon::now();
        $rows = array_map(fn (SearchResult $result): array => [
            'team_id' => $team->id,
            'artifact_id' => $result->id,
            'served_at' => $servedAt,
        ], $results);

        SearchResultServed::query()->insert($rows);
    }

    /**
     * @param  array<int, VectorMatch>  $candidates
     * @return array<string, float> artifact id => `UsageScorer::score()`
     */
    private function usageScoresFor(Team $team, array $candidates): array
    {
        $artifactIds = array_unique(array_map(fn (VectorMatch $match): string => $match->chunk->artifactId, $candidates));
        if ($artifactIds === []) {
            return [];
        }

        $eventsByArtifact = ArtifactUsageEvent::query()
            ->where('team_id', $team->id)
            ->whereIn('artifact_id', $artifactIds)
            ->get()
            ->groupBy('artifact_id');

        $now = Carbon::now();
        $scores = [];
        foreach ($eventsByArtifact as $artifactId => $events) {
            $scores[$artifactId] = UsageScorer::score($events->all(), $now);
        }

        return $scores;
    }

    /**
     * @return array<string, bool> artifact id => true, for every artifact
     *                             in any of this org's canonical collections
     */
    private function canonicalArtifactIdsFor(Team $team): array
    {
        $ids = Collection::query()
            ->where('team_id', $team->id)
            ->where('canonical', true)
            ->with('artifacts')
            ->get()
            ->flatMap(fn (Collection $collection) => $collection->artifactIds())
            ->all();

        return array_fill_keys($ids, true);
    }

    /**
     * @return array<string, array<string, mixed>> artifact id => directory row
     */
    private function artifactsBySlug(Team $team): array
    {
        $cursor = null;
        $byId = [];
        do {
            $page = $this->artifacts->listArtifacts($team->slug, [], $cursor, 200);
            foreach ($page['artifacts'] as $artifact) {
                $byId[$artifact['id']] = $artifact;
            }
            $cursor = $page['next_cursor'];
        } while ($cursor !== null);

        return $byId;
    }

    /**
     * @param  array<string, mixed>  $artifact
     */
    private function toResult(Team $team, VectorMatch $match, array $artifact, string $query): SearchResult
    {
        return new SearchResult(
            id: $match->chunk->artifactId,
            title: $artifact['title'] ?? $match->chunk->artifactId,
            description: $artifact['description'] ?? null,
            // The short viewer link (RUB-439), the same link every other
            // surface hands out, whatever the artifact's sharing level.
            url: ArtifactViewLink::forArtifact($team->slug, $match->chunk->artifactId, $artifact['tier'] ?? null),
            snippet: SearchSnippet::make($match->chunk->text, $query, $artifact['title'] ?? null),
            agent: $match->chunk->agent,
            repoUrl: $match->chunk->repoUrl,
            commitSha: $match->chunk->commitSha,
            score: $match->similarity,
            excerpt: SearchSnippet::make($match->chunk->text, $query, $artifact['title'] ?? null, SearchSnippet::EXCERPT_LENGTH),
        );
    }
}
