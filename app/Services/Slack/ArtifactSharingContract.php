<?php

namespace App\Services\Slack;

/**
 * The seam to the Worker's share configuration (spec 11's `shares`
 * table). Read-only — unfurling never changes an artifact's sharing.
 */
interface ArtifactSharingContract
{
    /**
     * @return SharingLevel|null null when the artifact doesn't exist —
     *                           callers treat that the same as OrgPrivate
     *                           (bare card), never distinguishing "no
     *                           such artifact" from "you can't see it."
     */
    public function sharingLevelFor(string $orgSlug, string $artifactId): ?SharingLevel;

    /**
     * The domain a domain-restricted artifact is restricted to, or null
     * if the artifact isn't domain-restricted.
     */
    public function restrictedDomainFor(string $orgSlug, string $artifactId): ?string;
}
