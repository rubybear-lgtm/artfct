<?php

namespace App\Contracts;

/**
 * Lists, filters, revokes, and exports artifacts from the Worker's D1.
 * Implemented by the real HTTP client and an in-memory fake for tests.
 */
interface ArtifactDirectory
{
    /**
     * List artifacts for an organization with optional filtering.
     *
     * @param  string  $orgSlug  Organization slug
     * @param  array<string, mixed>  $filters  Optional filters: user_id, repo_url, agent, date_from, date_to, size_min, size_max, q (free text)
     * @param  ?string  $cursor  Optional cursor for pagination on (created_at, id)
     * @param  int  $limit  Page size (default 50)
     * @return array{artifacts: list<array>, next_cursor: ?string}
     */
    public function listArtifacts(
        string $orgSlug,
        array $filters = [],
        ?string $cursor = null,
        int $limit = 50,
    ): array;

    /**
     * Revoke an artifact (soft delete via revoked_at).
     *
     * @param  string  $orgSlug  Organization slug
     * @param  string  $artifactId  Artifact identifier
     * @return array<string, mixed> Revoked artifact metadata
     *
     * @throws \Exception If artifact not found or access denied
     */
    public function revokeArtifact(string $orgSlug, string $artifactId): array;

    /**
     * Export all artifacts for an organization.
     *
     * @param  string  $orgSlug  Organization slug
     * @return array{artifacts: list<array>, blobs: array<string, string>}
     *
     * @throws \Exception If access denied or rate limited
     */
    public function exportArtifacts(string $orgSlug): array;
}
