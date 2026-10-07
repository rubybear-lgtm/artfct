<?php

namespace App\Services\Artifacts;

use App\Contracts\ArtifactDirectory;

/**
 * In-memory fake artifact directory for browser tests.
 * Provides test data without calling the Worker API.
 */
class FakeArtifactDirectory implements ArtifactDirectory
{
    /**
     * @var list<array> Demo artifacts, served for whichever org slug asks —
     *                  org-boundary enforcement happens upstream in ConsoleController/
     *                  ConsolePolicy before this fake is ever called, so this fake doesn't
     *                  need to model per-org data to be a faithful test double for the UI.
     */
    private array $artifacts = [];

    /**
     * @var array<string, int> Rate limit counters by org slug
     */
    private array $exportCounts = [];

    /**
     * @var array<string, array{id: string, current_version: int, versions: list<array<string, mixed>>}>
     *                                                                                                   Seeded version history by artifact id. An artifact without one reads
     *                                                                                                   as a single current version built from its artifact row.
     */
    private array $versions = [];

    /**
     * @var array<string, 'forbidden'|'conflict'|'not_found'> Test hook: make a
     *                                                        restore for this artifact answer with this status.
     */
    private array $restoreOutcomes = [];

    public function __construct()
    {
        // Initialize with some demo data
        $this->seedArtifacts();
    }

    /**
     * Adds one artifact to the in-memory demo set — for tests (spec 13's
     * search suite in particular) that need artifacts beyond the two
     * built-in seeds.
     *
     * @param  array<string, mixed>  $artifact
     */
    public function seedArtifact(array $artifact): void
    {
        $this->artifacts[] = $artifact;
    }

    public function listArtifacts(
        string $orgSlug,
        array $filters = [],
        ?string $cursor = null,
        int $limit = 50,
    ): array {
        $items = $this->artifacts;

        // Apply filters
        if (! empty($filters['repo_url'])) {
            $items = array_filter($items, fn ($a) => ($a['provenance']['repo_url'] ?? null) === $filters['repo_url']);
        }

        if (! empty($filters['agent'])) {
            $items = array_filter($items, fn ($a) => ($a['provenance']['agent'] ?? null) === $filters['agent']);
        }

        if (! empty($filters['user_id'])) {
            $items = array_filter($items, fn ($a) => (string) ($a['user_id'] ?? '') === (string) $filters['user_id']);
        }

        if (! empty($filters['q'])) {
            $q = strtolower($filters['q']);
            $items = array_filter(
                $items,
                fn ($a) => str_contains(strtolower($a['title'] ?? ''), $q) ||
                           str_contains(strtolower($a['description'] ?? ''), $q)
            );
        }

        // Cursor-based pagination
        if ($cursor) {
            $items = array_slice($items, (int) $cursor);
        }

        $hasMore = count($items) > $limit;
        $paginated = array_slice($items, 0, $limit);

        return [
            'artifacts' => $paginated,
            'next_cursor' => $hasMore ? (string) (($cursor ?: 0) + $limit) : null,
        ];
    }

    public function revokeArtifact(string $orgSlug, string $artifactId): array
    {
        foreach ($this->artifacts as &$artifact) {
            if ($artifact['id'] === $artifactId) {
                $artifact['revoked_at'] = now()->toIso8601String();

                return $artifact;
            }
        }

        throw new \Exception('Artifact not found', 404);
    }

    public function exportArtifacts(string $orgSlug): array
    {
        $this->exportCounts[$orgSlug] = ($this->exportCounts[$orgSlug] ?? 0) + 1;

        // Simulate rate limiting: allow 3 exports, then throttle
        if ($this->exportCounts[$orgSlug] > 3) {
            throw new \Exception('Rate limited', 429);
        }

        $artifacts = $this->artifacts;
        $blobs = [];

        foreach ($artifacts as $artifact) {
            $sha256 = hash('sha256', self::blobBody($artifact['content_hash']));
            $blobs[$sha256] = "/v1/blobs/{$sha256}";
        }

        return [
            'artifacts' => $artifacts,
            'blobs' => $blobs,
        ];
    }

    public function fetchBlob(string $orgSlug, string $sha256): ?string
    {
        foreach ($this->artifacts as $artifact) {
            $body = self::blobBody($artifact['content_hash']);

            if (hash('sha256', $body) === $sha256) {
                return $body;
            }
        }

        return null;
    }

    /**
     * Seed a version history for one artifact, newest first. `$currentVersion`
     * defaults to the highest version seeded.
     *
     * @param  list<array<string, mixed>>  $versions
     */
    public function seedVersions(string $artifactId, array $versions, ?int $currentVersion = null): void
    {
        $numbers = array_map(fn (array $version): int => (int) ($version['version'] ?? 0), $versions);

        $this->versions[$artifactId] = [
            'id' => $artifactId,
            'current_version' => $currentVersion ?? (int) max($numbers),
            'versions' => $versions,
        ];
    }

    /**
     * Test hook: make the next restore of `$artifactId` answer `forbidden`,
     * `conflict` or `not_found` instead of touching the seeded history.
     */
    public function failRestore(string $artifactId, string $status = 'forbidden'): void
    {
        $this->restoreOutcomes[$artifactId] = $status;
    }

    public function listVersions(string $orgSlug, string $artifactId): ?array
    {
        return $this->historyFor($artifactId);
    }

    public function restoreVersion(string $orgSlug, string $artifactId, int $version): array
    {
        if (isset($this->restoreOutcomes[$artifactId])) {
            return ['status' => $this->restoreOutcomes[$artifactId], 'version' => null];
        }

        $history = $this->historyFor($artifactId);
        if ($history === null) {
            return ['status' => 'not_found', 'version' => null];
        }

        $restored = null;
        foreach ($history['versions'] as $candidate) {
            if ((int) ($candidate['version'] ?? 0) === $version) {
                $restored = $candidate;
                break;
            }
        }

        if ($restored === null) {
            return ['status' => 'not_found', 'version' => null];
        }

        if ($version === (int) $history['current_version']) {
            return ['status' => 'unchanged', 'version' => $version];
        }

        // Restoring republishes the past version's content as a new version,
        // exactly as the Worker does: history stays append-only.
        $next = (int) $history['current_version'] + 1;
        $restored['version'] = $next;
        $restored['current'] = true;
        $restored['created_at'] = now()->toIso8601String();
        $restored['restored_from'] = $version;

        foreach ($history['versions'] as $index => $candidate) {
            $history['versions'][$index]['current'] = false;
        }

        array_unshift($history['versions'], $restored);
        $history['current_version'] = $next;
        $this->versions[$artifactId] = $history;

        return ['status' => 'restored', 'version' => $next];
    }

    /**
     * @return array{id: string, current_version: int, versions: list<array<string, mixed>>}|null
     */
    private function historyFor(string $artifactId): ?array
    {
        if (isset($this->versions[$artifactId])) {
            return $this->versions[$artifactId];
        }

        foreach ($this->artifacts as $artifact) {
            if ($artifact['id'] === $artifactId) {
                return [
                    'id' => $artifactId,
                    'current_version' => 1,
                    'versions' => [[
                        'version' => 1,
                        'created_at' => $artifact['created_at'] ?? now()->toIso8601String(),
                        'created_by' => isset($artifact['user_id']) ? (string) $artifact['user_id'] : null,
                        'agent' => $artifact['provenance']['agent'] ?? null,
                        'title' => $artifact['title'] ?? null,
                        'description' => $artifact['description'] ?? null,
                        'content_hash' => $artifact['content_hash'] ?? null,
                        'current' => true,
                        'restored_from' => null,
                    ]],
                ];
            }
        }

        return null;
    }

    private static function blobBody(string $contentHash): string
    {
        return "<!doctype html><title>{$contentHash}</title>";
    }

    private function seedArtifacts(): void
    {
        $this->artifacts = [
            [
                'id' => '1234567890',
                'org_id' => 'test-org',
                'user_id' => 1,
                'title' => 'Dashboard HTML',
                'description' => 'Interactive dashboard',
                'content_hash' => 'abcdef123456',
                'created_at' => now()->subDays(5)->toIso8601String(),
                'revoked_at' => null,
                'provenance' => [
                    'agent' => 'cursor',
                    'agent_raw' => 'Cursor',
                    'repo_url' => 'https://github.com/example/repo1',
                    'commit_sha' => 'abc123',
                ],
            ],
            [
                'id' => 'abcdefghij',
                'org_id' => 'test-org',
                'user_id' => 2,
                'title' => 'API Documentation',
                'description' => 'OpenAPI spec rendered',
                'content_hash' => 'fedcba654321',
                'created_at' => now()->subDays(2)->toIso8601String(),
                'revoked_at' => null,
                'provenance' => [
                    'agent' => 'claude-code',
                    'agent_raw' => 'Claude Code',
                    'repo_url' => 'https://github.com/example/repo2',
                    'commit_sha' => 'def456',
                ],
            ],
        ];
    }
}
