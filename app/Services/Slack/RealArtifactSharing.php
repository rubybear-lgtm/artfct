<?php

namespace App\Services\Slack;

use RuntimeException;

/**
 * Fails closed — reading share configuration needs the same live Worker
 * governance HTTP routes spec 11 left unwired in this environment
 * (`HttpArtifactGovernance`).
 */
final class RealArtifactSharing implements ArtifactSharingContract
{
    public function sharingLevelFor(string $orgSlug, string $artifactId): ?SharingLevel
    {
        $this->requireConfigured();
        throw new RuntimeException('RealArtifactSharing::sharingLevelFor is not implemented — the Worker has no live governance HTTP routes in this environment.');
    }

    public function restrictedDomainFor(string $orgSlug, string $artifactId): ?string
    {
        $this->requireConfigured();
        throw new RuntimeException('RealArtifactSharing::restrictedDomainFor is not implemented — the Worker has no live governance HTTP routes in this environment.');
    }

    private function requireConfigured(): void
    {
        if (! config('services.worker.base_url')) {
            throw new RuntimeException('services.worker.base_url must be configured to read sharing configuration.');
        }
    }
}
