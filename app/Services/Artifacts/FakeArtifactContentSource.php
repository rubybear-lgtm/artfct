<?php

namespace App\Services\Artifacts;

use App\Contracts\ArtifactContentSource;

/**
 * In-memory content source bound in `testing`. Keyed by org then artifact
 * id, so a foreign-org read has to miss on the org key.
 */
final class FakeArtifactContentSource implements ArtifactContentSource
{
    /** @var array<string, array<string, array{html: string, tier: ?string, version: ?int, sharing: ?string, owner_user_id: ?string, entrypoint: string, files: ?list<array{path: string, sha256: string, content_type: string, size_bytes: int}>, provenance: array{agent: ?string, repo_url: ?string, commit_sha: ?string}}>> */
    private array $artifacts = [];

    /**
     * @param  array{agent: ?string, repo_url: ?string, commit_sha: ?string}  $provenance
     *
     * `$tier` defaults to `secure`: a seeded artifact that says nothing about
     * its tier must behave like the one that needs a mint, not like the one the
     * Worker serves to anyone. `$version` defaults to 1, matching an artifact
     * published after versioning existed. `$sharing` defaults to `team`, the
     * Worker's default when neither sharing nor tier is given.
     *
     * `$files` is the bundle's file list. When omitted, the bundle is the
     * entrypoint alone (`index.html`), whose sha256 is the sha of `$html`.
     * @param  ?list<array{path: string, sha256: string, content_type: string, size_bytes: int}>  $files
     * @param  string  $entrypoint  the bundle's entrypoint path
     */
    public function seed(string $orgSlug, string $artifactId, string $html, array $provenance = ['agent' => null, 'repo_url' => null, 'commit_sha' => null], string $tier = 'secure', ?int $version = 1, string $sharing = 'team', ?string $ownerUserId = null, ?array $files = null, string $entrypoint = 'index.html'): void
    {
        $this->artifacts[$orgSlug][$artifactId] = [
            'html' => $html,
            'tier' => $tier,
            'version' => $version,
            'sharing' => $sharing,
            'owner_user_id' => $ownerUserId,
            'entrypoint' => $entrypoint,
            'files' => $files ?? [[
                'path' => 'index.html',
                'sha256' => hash('sha256', $html),
                'content_type' => 'text/html',
                'size_bytes' => strlen($html),
            ]],
            'provenance' => $provenance,
        ];
    }

    public function fetch(string $orgSlug, string $artifactId): ?array
    {
        return $this->artifacts[$orgSlug][$artifactId] ?? null;
    }

    /**
     * Test hook: the Worker stops listing this artifact's files, as an older
     * Worker does, so the packer must refuse.
     */
    public function markFilesUnknown(string $orgSlug, string $artifactId): void
    {
        $this->artifacts[$orgSlug][$artifactId]['files'] = null;
    }
}
