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
 *
 * Worker metadata is loaded only for scored candidates (`ids` + `live`),
 * so Worker round trips stay O(results) instead of O(org size) (RUB-478).
 */
final class SearchService
{
    /** The Worker refuses a longer `ids` list on one list call. */
    private const WORKER_IDS_PAGE = 200;

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
        // Laravel-side scopes (collection / artifact type) narrow the vector
        // and lexical queries. Null means org-wide; [] means nothing matches.
        $scopeIds = $this->laravelScopeIds($team, $filters);
        if ($scopeIds === []) {
            return $this->finishEmpty($team, $query, $actor, $ip, $userAgent);
        }

        $queryVector = $this->embeddings->embedQuery($query);
        $candidateLimit = max($limit * 5, 50);
        $vectorCandidates = $this->vectorIndex->queryArtifacts($team->slug, $queryVector, $candidateLimit, $scopeIds);
        $lexicalCandidates = $this->vectorIndex->queryTextArtifacts($team->slug, $query, $candidateLimit, $scopeIds);
        $candidates = ReciprocalRankFusion::combine($vectorCandidates, $lexicalCandidates);
        $candidates = array_values(array_filter(
            $candidates,
            fn (VectorMatch $match): bool => $match->chunk->orgId === $team->slug,
        ));

        $candidateIds = array_values(array_unique(array_map(
            fn (VectorMatch $match): string => $match->chunk->artifactId,
            $candidates,
        )));
        $directory = $this->artifactsByIds($team, $candidateIds);

        $isAllowed = function (string $id) use ($directory, $filters): bool {
            $artifact = $directory[$id] ?? null;
            if ($artifact === null || ($artifact['revoked_at'] ?? null) !== null) {
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

        $filtered = array_values(array_filter(
            $candidates,
            fn (VectorMatch $match): bool => $isAllowed($match->chunk->artifactId),
        ));
        $filtered = array_slice($filtered, 0, max(20, $limit));
        $introductions = $this->vectorIndex->firstChunks($team->slug, array_map(fn (VectorMatch $match): string => $match->chunk->artifactId, $filtered), 2);
        $passages = SearchPassageSelection::expand($filtered, $introductions, [...$vectorCandidates, ...$lexicalCandidates], $query, array_map(fn (array $artifact): ?string => $artifact['title'] ?? null, $directory));
        $filtered = SearchPassageSelection::bestArtifacts($this->reranker->rerank($query, $passages), (float) config('indexing.minimum_search_relevance', 0.05), $query);

        $usageScores = $this->usageScoresFor($team, $filtered);
        $canonicalArtifactIds = $this->canonicalArtifactIdsFor($team);

        $ranked = SearchRanking::rank($filtered, $query, $usageScores, $canonicalArtifactIds);
        $top = array_slice($ranked, 0, $limit);

        $types = app(ArtifactTypeResolver::class)->typesFor($team, array_map(fn (VectorMatch $match): string => $match->chunk->artifactId, $top));
        $results = array_map(
            fn (VectorMatch $match): SearchResult => $this->toResult($team, $match, $directory[$match->chunk->artifactId], $query, $types[$match->chunk->artifactId] ?? null),
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
     * Collection and artifact-type scopes live in Laravel. Null means no
     * Laravel scope (search the whole org index); an empty list means the
     * scope matches nothing and search can return without touching the Worker.
     *
     * @param  array{collection?: ?string, artifact_type?: ?string}  $filters
     * @return list<string>|null
     */
    private function laravelScopeIds(Team $team, array $filters): ?array
    {
        $ids = null;

        if (! empty($filters['collection'])) {
            $collection = Collection::query()->where('team_id', $team->id)->where('name', $filters['collection'])->first();
            $ids = $collection === null ? [] : $collection->artifactIds();
        }

        if (! empty($filters['artifact_type'])) {
            $typeIds = app(ArtifactTypeResolver::class)->artifactIdsOfType($team, ArtifactType::from($filters['artifact_type']));
            $ids = $ids === null ? $typeIds : array_values(array_intersect($ids, $typeIds));
        }

        return $ids;
    }

    /**
     * @return array<int, SearchResult>
     */
    private function finishEmpty(
        Team $team,
        string $query,
        string $actor,
        string $ip,
        string $userAgent,
    ): array {
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
     * Fetch Worker list rows for the scored candidate ids only. `live=true`
     * keeps revoked artifacts out; chunking respects the Worker's 200-id cap.
     *
     * @param  list<string>  $ids
     * @return array<string, array<string, mixed>> artifact id => directory row
     */
    private function artifactsByIds(Team $team, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $byId = [];
        foreach (array_chunk($ids, self::WORKER_IDS_PAGE) as $chunk) {
            $page = $this->artifacts->listArtifacts(
                $team->slug,
                [
                    'ids' => implode(',', $chunk),
                    'live' => 'true',
                ],
                null,
                count($chunk),
            );
            foreach ($page['artifacts'] as $artifact) {
                $byId[$artifact['id']] = $artifact;
            }
        }

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
            score: $match->similarity,
            excerpt: SearchSnippet::make($match->chunk->text, $query, $artifact['title'] ?? null, SearchSnippet::EXCERPT_LENGTH),
            version: isset($artifact['version']) ? (int) $artifact['version'] : null,
            updatedAt: $artifact['updated_at'] ?? $artifact['created_at'] ?? null,
            artifactType: $artifactType,
        );
    }
}
