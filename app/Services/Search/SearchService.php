<?php

namespace App\Services\Search;

use App\Contracts\ArtifactDirectory;
use App\Enums\ArtifactType;
use App\Enums\AuditEventType;
use App\Models\ArtifactUsageEvent;
use App\Models\Collection;
use App\Models\SearchResultServed;
use App\Models\Team;
use App\Services\Artifacts\ArtifactTypeResolver;
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
     * @param  array{repo?: ?string, agent?: ?string, since?: ?string, collection?: ?string, changed_since?: ?string, artifact_type?: ?string}  $filters
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
        // RUB-515: unknown collection is an error (same as list_artifacts),
        // not an empty result set. Accept name or numeric id.
        $collectionArtifactIds = null;
        if (! empty($filters['collection'])) {
            $lookup = trim((string) $filters['collection']);
            $collectionQuery = Collection::query()->where('team_id', $team->id);
            $collection = ctype_digit($lookup)
                ? (clone $collectionQuery)->where('id', (int) $lookup)->first()
                    ?? (clone $collectionQuery)->where('name', $lookup)->first()
                : $collectionQuery->where('name', $lookup)->first();
            if ($collection === null) {
                throw new CollectionNotFoundException('That collection was not found in the authenticated workspace.');
            }
            $collectionArtifactIds = array_flip($collection->artifactIds());
        }

        $typeIds = null;
        if (! empty($filters['artifact_type'])) {
            $typeIds = array_flip(app(ArtifactTypeResolver::class)->artifactIdsOfType($team, ArtifactType::from($filters['artifact_type'])));
        }

        $isAllowed = function (string $id) use ($directory, $filters, $collectionArtifactIds, $typeIds): bool {
            $artifact = $directory[$id] ?? null;
            if ($artifact === null || $artifact['revoked_at'] !== null) {
                return false;
            }
            if ($collectionArtifactIds !== null && ! isset($collectionArtifactIds[$id])) {
                return false;
            }
            if ($typeIds !== null && ! isset($typeIds[$id])) {
                return false;
            }
            if (! empty($filters['changed_since']) && CarbonImmutable::parse($artifact['updated_at'] ?? $artifact['created_at'])->lt(CarbonImmutable::parse($filters['changed_since']))) {
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
        $candidateLimit = max($limit * 5, 50);
        $titles = array_map(fn (array $artifact): ?string => $artifact['title'] ?? null, $directory);

        // Empty workspace / fully filtered: skip Workers AI so a no-results
        // search cannot burn ~10s on an embedding call (RUB-516).
        if ($allowedIds === []) {
            $this->auditLogger->record(
                AuditEventType::SearchPerformed,
                $team,
                $actor,
                $query,
                $ip,
                $userAgent,
                '0 result(s)',
            );

            return [];
        }

        $vectorCandidates = $this->vectorCandidates($team->slug, $query, $candidateLimit, $allowedIds);
        $lexicalCandidates = $this->vectorIndex->queryTextArtifacts($team->slug, $query, $candidateLimit, $allowedIds);
        $candidates = ReciprocalRankFusion::combine($vectorCandidates, $lexicalCandidates);
        $filtered = array_values(array_filter($candidates, fn (VectorMatch $match): bool => $match->chunk->orgId === $team->slug && $isAllowed($match->chunk->artifactId)));

        $filtered = array_slice($filtered, 0, max(20, $limit));
        $introductions = $this->vectorIndex->firstChunks($team->slug, array_map(fn (VectorMatch $match): string => $match->chunk->artifactId, $filtered), 2);
        $passages = SearchPassageSelection::expand($filtered, $introductions, [...$vectorCandidates, ...$lexicalCandidates], $query, $titles);
        $filtered = SearchPassageSelection::bestArtifacts($this->reranker->rerank($query, $passages), (float) config('indexing.minimum_search_relevance', 0.05), $query);

        $usageScores = $this->usageScoresFor($team, $filtered);
        $canonicalArtifactIds = $this->canonicalArtifactIdsFor($team);

        $ranked = SearchRanking::rank($filtered, $query, $usageScores, $canonicalArtifactIds, $titles);
        $top = array_slice($ranked, 0, $limit);

        $types = app(ArtifactTypeResolver::class)->typesFor($team, array_map(fn (VectorMatch $match): string => $match->chunk->artifactId, $top));
        $results = array_map(
            fn (VectorMatch $match): SearchResult => $this->toResult($team, $match, $directory[$match->chunk->artifactId], $query, $types[$match->chunk->artifactId] ?? null),
            $top,
        );

        // Returned `score` is displayScore; keep the wire order identical so
        // clients sorting by score match what they see (RUB-516).
        usort(
            $results,
            fn (SearchResult $a, SearchResult $b): int => $b->score <=> $a->score,
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
     * Embed the query and run the vector index. On Workers AI timeout or a
     * connection failure, fall back to lexical-only retrieval so a cold or
     * hung embedding call cannot push a no-results search past ~1.5s.
     *
     * @param  list<string>  $allowedIds
     * @return array<int, VectorMatch>
     */
    private function vectorCandidates(string $orgId, string $query, int $candidateLimit, array $allowedIds): array
    {
        try {
            $queryVector = $this->embeddings->embedQuery($query);

            return $this->vectorIndex->queryArtifacts($orgId, $queryVector, $candidateLimit, $allowedIds);
        } catch (\Throwable $exception) {
            if (! $this->isEmbeddingTimeout($exception)) {
                throw $exception;
            }

            report($exception);

            return [];
        }
    }

    private function isEmbeddingTimeout(\Throwable $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'timed out')
            || str_contains($message, 'timeout')
            || str_contains($message, 'could not connect');
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
    private function toResult(Team $team, VectorMatch $match, array $artifact, string $query, ?string $artifactType = null): SearchResult
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
            score: SearchRanking::displayScore($match->similarity, $query, $artifact['title'] ?? null),
            excerpt: SearchSnippet::make($match->chunk->text, $query, $artifact['title'] ?? null, SearchSnippet::EXCERPT_LENGTH),
            version: isset($artifact['version']) ? (int) $artifact['version'] : null,
            updatedAt: $artifact['updated_at'] ?? $artifact['created_at'] ?? null,
            artifactType: $artifactType,
        );
    }
}
