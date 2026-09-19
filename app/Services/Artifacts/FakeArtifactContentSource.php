<?php

namespace App\Services\Artifacts;

use App\Contracts\ArtifactContentSource;

/**
 * In-memory content source bound in `testing`. Keyed by org then artifact
 * id, so a foreign-org read has to miss on the org key.
 */
final class FakeArtifactContentSource implements ArtifactContentSource
{
    /** @var array<string, array<string, array{html: string, provenance: array{agent: ?string, repo_url: ?string, commit_sha: ?string}}>> */
    private array $artifacts = [];

    /**
     * @param  array{agent: ?string, repo_url: ?string, commit_sha: ?string}  $provenance
     */
    public function seed(string $orgSlug, string $artifactId, string $html, array $provenance = ['agent' => null, 'repo_url' => null, 'commit_sha' => null]): void
    {
        $this->artifacts[$orgSlug][$artifactId] = ['html' => $html, 'provenance' => $provenance];
    }

    public function fetch(string $orgSlug, string $artifactId): ?array
    {
        return $this->artifacts[$orgSlug][$artifactId] ?? null;
    }
}
