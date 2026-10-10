<?php

namespace App\Services\Slack;

final class FakeArtifactSharing implements ArtifactSharingContract
{
    /** @var array<string, array{level: SharingLevel, domain: ?string}> */
    private array $sharing = [];

    public function seed(string $orgSlug, string $artifactId, SharingLevel $level, ?string $domain = null): void
    {
        $this->sharing["{$orgSlug}:{$artifactId}"] = ['level' => $level, 'domain' => $domain];
    }

    public function sharingLevelFor(string $orgSlug, string $artifactId): ?SharingLevel
    {
        return $this->sharing["{$orgSlug}:{$artifactId}"]['level'] ?? null;
    }

    public function restrictedDomainFor(string $orgSlug, string $artifactId): ?string
    {
        return $this->sharing["{$orgSlug}:{$artifactId}"]['domain'] ?? null;
    }
}
