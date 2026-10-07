<?php

namespace App\Services\Artifacts;

use App\Contracts\PublicArtifactSource;

/**
 * In-memory public-artifact source bound in `testing`, so the viewer's public
 * path can be exercised without a Worker. Keyed by artifact id: an id that was
 * never seeded reads exactly like the Worker's 404.
 */
final class FakePublicArtifactSource implements PublicArtifactSource
{
    /** @var array<string, array<string, mixed>> */
    private array $artifacts = [];

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function seed(string $artifactId, string $orgSlug, array $overrides = []): void
    {
        $this->artifacts[$artifactId] = array_merge([
            'id' => $artifactId,
            'org' => $orgSlug,
            'title' => null,
            'description' => null,
            'version' => 1,
            'version_count' => 1,
            'updated_at' => null,
            'sharing' => 'public',
            'edit_access' => 'view',
        ], $overrides);
    }

    public function find(string $artifactId): ?array
    {
        return $this->artifacts[$artifactId] ?? null;
    }
}
