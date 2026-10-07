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

    /**
     * List one artifact's completed versions, newest first.
     *
     * @param  string  $orgSlug  Organization slug
     * @param  string  $artifactId  Permanent artifact identifier
     * @return array{id: string, current_version: int, versions: list<array<string, mixed>>}|null
     *                                                                                            Null when the artifact is not in this organization (the Worker answers 404).
     *
     * @throws \Exception If access denied or the artifact service fails
     */
    public function listVersions(string $orgSlug, string $artifactId): ?array;

    /**
     * Publish a past version as the artifact's current content. Restoring the
     * current version is a no-op (`unchanged`), never an error.
     *
     * @param  string  $orgSlug  Organization slug
     * @param  string  $artifactId  Permanent artifact identifier
     * @param  int  $version  The version to restore (1-based)
     * @return array{status: 'restored'|'unchanged'|'forbidden'|'not_found'|'conflict', version: ?int}
     */
    public function restoreVersion(string $orgSlug, string $artifactId, int $version): array;

    /**
     * Fetch one export blob's bytes, or null when the organization has no
     * permanent artifact referencing it.
     *
     * @param  string  $orgSlug  Organization slug
     * @param  string  $sha256  Lowercase hex SHA-256 of the blob
     *
     * @throws \Exception If access denied
     */
    public function fetchBlob(string $orgSlug, string $sha256): ?string;
}
