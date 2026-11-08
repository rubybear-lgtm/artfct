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

    /** One widening pass when hidden rows crowd visible ones out of the candidates. */
    private const WIDEN_FACTOR = 4;

    private const WIDENED_CANDIDATE_CAP = 1000;

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
        // Fail closed before scoring: a zero-candidate search must not skip the
        // viewer-credential gate that used to run on the full-directory list.
        $this->artifacts->requireViewerCredential($team->slug);

        // Laravel-side scopes (collection / artifact type) narrow the vector
        // and lexical queries. Null means org-wide; [] means nothing matches.
        $scopeIds = $this->laravelScopeIds($team, $filters);
        if ($scopeIds === []) {
            return $this->finishEmpty($team, $query, $actor, $ip, $userAgent);
        }

        // Nothing indexed for this workspace: skip Workers AI so a no-results
        // search cannot burn seconds on an embedding call (RUB-516).
        if (! $this->vectorIndex->hasIndexedChunks($team->slug)) {
            return $this->finishEmpty($team, $query, $actor, $ip, $userAgent);
        }

        // Filters that only the Worker's metadata can answer (repo, agent, since,
        // changed_since) must narrow retrieval *before* the candidate cut, or a
        // narrow filter over a large org returns too few results. Those searches
        // read the directory first, as before RUB-478; unfiltered searches stay
        // bounded to their scored candidates.
        $directory = null;
        $retrievalIds = $scopeIds;
        if ($this->filtersNeedDirectory($filters)) {
            $directory = $this->artifactsBySlug($team);
            $scopeLookup = $scopeIds === null ? null : array_flip($scopeIds);
            $retrievalIds = array_values(array_filter(
                array_map(strval(...), array_keys($directory)),
                fn (string $id): bool => ($scopeLookup === null || isset($scopeLookup[$id]))
                    && $this->passesDirectoryFilters($directory[$id], $filters),
            ));

            if ($retrievalIds === []) {
                return $this->finishEmpty($team, $query, $actor, $ip, $userAgent);
            }
        }

        $candidateLimit = max($limit * 5, 50);
        [$vectorCandidates, $lexicalCandidates, $candidates] = $this->retrieveCandidates($team, $query, $candidateLimit, $retrievalIds);

        if ($directory === null) {
            $directory = $this->artifactsByIds($team, $this->candidateArtifactIds($candidates));

            // Retrieval ranks every indexed passage, including ones the Worker
            // hides from this viewer (other members' private artifacts, revoked
            // rows). When a full candidate list leaves too few visible
            // artifacts, widen it once rather than return a short result.
            $widenedLimit = min($candidateLimit * self::WIDEN_FACTOR, self::WIDENED_CANDIDATE_CAP);
            if (
                $widenedLimit > $candidateLimit
                && (count($vectorCandidates) >= $candidateLimit || count($lexicalCandidates) >= $candidateLimit)
                && $this->visibleArtifactCount($candidates, $directory, $filters) < $limit
            ) {
                [$vectorCandidates, $lexicalCandidates, $candidates] = $this->retrieveCandidates($team, $query, $widenedLimit, $retrievalIds);
                $unseen = array_values(array_diff($this->candidateArtifactIds($candidates), array_keys($directory)));
                // array_replace, not a spread: a spread renumbers integer-like keys.
                $directory = array_replace($directory, $this->artifactsByIds($team, $unseen));
            }
        }
        $titles = array_map(fn (array $artifact): ?string => $artifact['title'] ?? null, $directory);

        $isAllowed = fn (string $id): bool => $this->passesDirectoryFilters($directory[$id] ?? null, $filters);

        $filtered = array_values(array_filter(
            $candidates,
            fn (VectorMatch $match): bool => $isAllowed($match->chunk->artifactId),
        ));
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

        // Rank order already accounts for title tier, relevance, and boosts
        // (repo / usage / canonical). Raw displayScore can disagree when a
        // lower-similarity repo match outranks a semantic-only hit — do not
        // re-sort; instead keep ranked order and make returned scores
        // non-increasing so wire order matches score order (RUB-516).
        $alignedScores = SearchRanking::alignScoresWithOrder(
            array_map(fn (SearchResult $result): float => $result->score, $results),
        );
        $results = array_map(
            function (SearchResult $result, int $index) use ($alignedScores): SearchResult {
                $score = $alignedScores[$index];
                if ($score === $result->score) {
                    return $result;
                }

                return new SearchResult(
                    id: $result->id,
                    title: $result->title,
                    description: $result->description,
                    url: $result->url,
                    snippet: $result->snippet,
                    agent: $result->agent,
                    repoUrl: $result->repoUrl,
                    commitSha: $result->commitSha,
                    score: $score,
                    excerpt: $result->excerpt,
                    version: $result->version,
                    updatedAt: $result->updatedAt,
                    artifactType: $result->artifactType,
                );
            },
            $results,
            array_keys($results),
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
            // RUB-515: an unknown collection is an error (same as list_artifacts),
            // not an empty result set. Accept a name or a numeric id.
            $lookup = trim((string) $filters['collection']);
            $collectionQuery = Collection::query()->where('team_id', $team->id);
            $collection = ctype_digit($lookup)
                ? (clone $collectionQuery)->where('id', (int) $lookup)->first()
                    ?? (clone $collectionQuery)->where('name', $lookup)->first()
                : $collectionQuery->where('name', $lookup)->first();
            if ($collection === null) {
                throw new CollectionNotFoundException('That collection was not found in the authenticated workspace.');
            }
            $ids = $collection->artifactIds();
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
     * Embed the query and run the vector index. On Workers AI timeout or a
     * connection failure, fall back to lexical-only retrieval so a cold or
     * hung embedding call cannot push a no-results search past ~1.5s.
     *
     * @param  list<string>|null  $scopeIds
     * @return array<int, VectorMatch>
     */
    private function vectorCandidates(string $orgId, string $query, int $candidateLimit, ?array $scopeIds): array
    {
        try {
            $queryVector = $this->embeddings->embedQuery($query);

            return $this->vectorIndex->queryArtifacts($orgId, $queryVector, $candidateLimit, $scopeIds);
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
     * Vector + lexical retrieval fused into one candidate list for the org.
     *
     * @param  list<string>|null  $scopeIds
     * @return array{0: array<int, VectorMatch>, 1: array<int, VectorMatch>, 2: array<int, VectorMatch>} vector, lexical, fused candidates
     */
    private function retrieveCandidates(Team $team, string $query, int $candidateLimit, ?array $scopeIds): array
    {
        $vectorCandidates = $this->vectorCandidates($team->slug, $query, $candidateLimit, $scopeIds);
        $lexicalCandidates = $this->vectorIndex->queryTextArtifacts($team->slug, $query, $candidateLimit, $scopeIds);
        $candidates = array_values(array_filter(
            ReciprocalRankFusion::combine($vectorCandidates, $lexicalCandidates),
            fn (VectorMatch $match): bool => $match->chunk->orgId === $team->slug,
        ));

        return [$vectorCandidates, $lexicalCandidates, $candidates];
    }

    /**
     * @param  array<int, VectorMatch>  $candidates
     * @return list<string>
     */
    private function candidateArtifactIds(array $candidates): array
    {
        return array_values(array_unique(array_map(
            fn (VectorMatch $match): string => $match->chunk->artifactId,
            $candidates,
        )));
    }

    /**
     * Distinct candidate artifacts the viewer can see and the filters keep.
     *
     * @param  array<int, VectorMatch>  $candidates
     * @param  array<string, array<string, mixed>>  $directory
     * @param  array<string, mixed>  $filters
     */
    private function visibleArtifactCount(array $candidates, array $directory, array $filters): int
    {
        return count(array_filter(
            $this->candidateArtifactIds($candidates),
            fn (string $id): bool => $this->passesDirectoryFilters($directory[$id] ?? null, $filters),
        ));
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function filtersNeedDirectory(array $filters): bool
    {
        return ! empty($filters['repo'])
            || ! empty($filters['agent'])
            || ! empty($filters['since'])
            || ! empty($filters['changed_since']);
    }

    /**
     * Whether a directory row survives the Worker-metadata filters. A missing
     * row (foreign, deleted, not live) or a revoked one never does.
     *
     * @param  array<string, mixed>|null  $artifact
     * @param  array<string, mixed>  $filters
     */
    private function passesDirectoryFilters(?array $artifact, array $filters): bool
    {
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
    }

    /**
     * Every directory row for the org, paged. Only searches that filter on
     * Worker metadata need the whole directory.
     *
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
            agent: $match->chunk->agent ?? ($artifact['provenance']['agent'] ?? null),
            repoUrl: $match->chunk->repoUrl,
            commitSha: $match->chunk->commitSha,
            model: is_string($artifact['provenance']['model'] ?? null) ? $artifact['provenance']['model'] : null,
            score: SearchRanking::displayScore($match->similarity, $query, $artifact['title'] ?? null),
            excerpt: SearchSnippet::make($match->chunk->text, $query, $artifact['title'] ?? null, SearchSnippet::EXCERPT_LENGTH),
            version: isset($artifact['version']) ? (int) $artifact['version'] : null,
            updatedAt: $artifact['updated_at'] ?? $artifact['created_at'] ?? null,
            artifactType: $artifactType,
        );
    }
}
