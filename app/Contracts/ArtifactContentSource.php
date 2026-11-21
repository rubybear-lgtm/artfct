<?php

namespace App\Contracts;

/**
 * Reads an artifact's entrypoint and provenance from the Worker with the
 * org credential (spec 12's event-driven indexing). Returns null when the
 * artifact does not exist in that org, so a foreign or revoked artifact is
 * indistinguishable from a missing one.
 */
interface ArtifactContentSource
{
    /**
     * @return array{
     *     html: string,
     *     tier: ?string,
     *     sharing: ?string,
     *     owner_user_id: ?string,
     *     version: ?int,
     *     entrypoint: ?string,
     *     files: ?list<array{path: string, sha256: string, content_type: string, size_bytes: int}>,
     *     provenance: array{agent: ?string, repo_url: ?string, commit_sha: ?string}
     * }|null  `files` is null when the Worker did not list the bundle's files
     */
    public function fetch(string $orgSlug, string $artifactId): ?array;
}
