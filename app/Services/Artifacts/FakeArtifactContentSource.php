<?php

namespace App\Services\Artifacts;

use App\Contracts\ArtifactContentSource;

/**
 * In-memory content source bound in `testing`. Keyed by org then artifact
 * id, so a foreign-org read has to miss on the org key.
 */
final class FakeArtifactContentSource implements ArtifactContentSource
{
    /** @var array<string, array<string, array{html: string, tier: ?string, version: ?int, provenance: array{agent: ?string, repo_url: ?string, commit_sha: ?string}}>> */
    private array $artifacts = [];

    /**
     * @param  array{agent: ?string, repo_url: ?string, commit_sha: ?string}  $provenance
     *
     * `$tier` defaults to `secure`: a seeded artifact that says nothing about
     * its tier must behave like the one that needs a mint, not like the one the
     * Worker serves to anyone. `$version` defaults to 1, matching an artifact
     * published after versioning existed.
     */
    public function seed(string $orgSlug, string $artifactId, string $html, array $provenance = ['agent' => null, 'repo_url' => null, 'commit_sha' => null], string $tier = 'secure', ?int $version = 1): void
    {
        $this->artifacts[$orgSlug][$artifactId] = ['html' => $html, 'tier' => $tier, 'version' => $version, 'provenance' => $provenance];
    }

    public function fetch(string $orgSlug, string $artifactId): ?array
    {
        return $this->artifacts[$orgSlug][$artifactId] ?? null;
    }
}
