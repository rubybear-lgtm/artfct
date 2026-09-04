<?php

namespace App\Services\Search;

use App\Contracts\ArtifactDirectory;
use App\Enums\AuditEventType;
use App\Models\Team;
use App\Services\Governance\AuditLogger;
use App\Services\Indexing\EmbeddingsContract;
use App\Services\Indexing\VectorIndexContract;
use App\Services\Indexing\VectorMatch;
use Carbon\CarbonImmutable;

/**
 * `search_artifacts` (spec 13): hybrid semantic + recency + provenance
 * ranking, strictly scoped to the caller's org, excluding revoked/
 * inaccessible artifacts, auditing every search.
 */
final class SearchService
{
    public function __construct(
        private readonly EmbeddingsContract $embeddings,
        private readonly VectorIndexContract $vectorIndex,
        private readonly ArtifactDirectory $artifacts,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @param  array{repo?: ?string, agent?: ?string, since?: ?string}  $filters
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
        [$queryVector] = $this->embeddings->embed([$query]);

        // Overfetch: revoked/inaccessible artifacts and repo/agent/since
        // filters all reduce the candidate set after the vector query, so
        // asking the index for exactly $limit would silently under-return.
        $candidates = $this->vectorIndex->query($team->slug, $queryVector, max($limit * 5, 50));

        $directory = $this->artifactsBySlug($team);

        $filtered = array_values(array_filter(
            $candidates,
            function (VectorMatch $match) use ($directory, $filters): bool {
                $artifact = $directory[$match->chunk->artifactId] ?? null;

                // Not in the directory (deleted/never existed) or revoked:
                // both simply vanish from results — indistinguishable from
                // never having matched at all (DoD: "its non-appearance is
                // indistinguishable from non-existence").
                if ($artifact === null || $artifact['revoked_at'] !== null) {
                    return false;
                }

                if (! empty($filters['repo']) && ($artifact['provenance']['repo_url'] ?? null) !== $filters['repo']) {
                    return false;
                }

                if (! empty($filters['agent']) && ($artifact['provenance']['agent'] ?? null) !== $filters['agent']) {
                    return false;
                }

                if (! empty($filters['since'])) {
                    $since = CarbonImmutable::parse($filters['since']);
                    if (CarbonImmutable::parse($artifact['created_at'])->lt($since)) {
                        return false;
                    }
                }

                return true;
            },
        ));

        $ranked = SearchRanking::rank($filtered, $query);
        $top = array_slice($ranked, 0, $limit);

        $results = array_map(
            fn (VectorMatch $match): SearchResult => $this->toResult($match, $directory[$match->chunk->artifactId]),
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

        return $results;
    }

    /**
     * @return array<string, array<string, mixed>> artifact id => directory row
     */
    private function artifactsBySlug(Team $team): array
    {
        $page = $this->artifacts->listArtifacts($team->slug, [], null, 1000);
        $byId = [];
        foreach ($page['artifacts'] as $artifact) {
            $byId[$artifact['id']] = $artifact;
        }

        return $byId;
    }

    /**
     * @param  array<string, mixed>  $artifact
     */
    private function toResult(VectorMatch $match, array $artifact): SearchResult
    {
        return new SearchResult(
            id: $match->chunk->artifactId,
            title: $artifact['title'] ?? $match->chunk->artifactId,
            description: $artifact['description'] ?? null,
            url: rtrim((string) config('app.public_base_url', 'https://artfct.dev'), '/')."/p/{$match->chunk->artifactId}",
            snippet: $this->snippet($match->chunk->text),
            agent: $match->chunk->agent,
            repoUrl: $match->chunk->repoUrl,
            commitSha: $match->chunk->commitSha,
            score: $match->similarity,
        );
    }

    /**
     * A short excerpt, never the full chunk/document text — DoD: "never
     * the full bundle."
     */
    private function snippet(string $text, int $maxLength = 200): string
    {
        $trimmed = trim($text);
        if (mb_strlen($trimmed) <= $maxLength) {
            return $trimmed;
        }

        return mb_substr($trimmed, 0, $maxLength).'…';
    }
}
