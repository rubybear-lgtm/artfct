<?php

namespace App\Services\Indexing;

/**
 * One chunk about to be upserted, carrying the provenance metadata spec 12
 * requires on every chunk: "artifact_id, org_id, created_at, agent,
 * repo_url, commit_sha — the provenance captured back in spec 2."
 */
final readonly class VectorChunk
{
    /**
     * @param  array<int, float>  $vector
     */
    public function __construct(
        public string $text,
        public array $vector,
        public string $artifactId,
        public string $orgId,
        public string $createdAt,
        public ?string $agent,
        public ?string $repoUrl,
        public ?string $commitSha,
    ) {}
}
