<?php

namespace App\Services\Search;

/**
 * One search result: "title, description, URL, provenance summary and a
 * snippet — never the full bundle" (spec 13).
 */
final readonly class SearchResult
{
    /** Raw query/passage reranker relevance; model-specific, not the boosted ordering score or a probability. */
    public function __construct(
        public string $id,
        public string $title,
        public ?string $description,
        public string $url,
        public string $snippet,
        public ?string $agent,
        public ?string $repoUrl,
        public ?string $commitSha,
        public float $score,
        /** Longer query-focused excerpt of the selected passage, for callers that cannot open the artifact (MCP). */
        public string $excerpt = '',
    ) {}
}
