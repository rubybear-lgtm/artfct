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
     * @param  ?int  $timeoutSeconds  total request timeout in seconds; null keeps the source default.
     *                                Request-path callers (read_artifact serving the current version)
     *                                pass a shorter bound; the queue indexer keeps the default.
     */
    public function fetch(string $orgSlug, string $artifactId, ?int $timeoutSeconds = null): ?array;
}
