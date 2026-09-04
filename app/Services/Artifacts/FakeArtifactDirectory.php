<?php

namespace App\Services\Artifacts;

use App\Contracts\ArtifactDirectory;

/**
 * In-memory fake artifact directory for browser tests.
 * Provides test data without calling the Worker API.
 */
final class FakeArtifactDirectory implements ArtifactDirectory
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
            $items = array_filter($items, fn ($a) => ($a['user_id'] ?? null) === $filters['user_id']);
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
            'artifacts' => array_values($paginated),
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
            $blobs[$artifact['content_hash']] = "/v1/blobs/{$artifact['content_hash']}";
        }

        return [
            'artifacts' => $artifacts,
            'blobs' => $blobs,
        ];
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
