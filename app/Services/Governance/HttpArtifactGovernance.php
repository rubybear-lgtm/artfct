<?php

namespace App\Services\Governance;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Calls the Worker's internal governance routes
 * (`/v1/internal/orgs/{org}/governance/...`, RUB-317) with the governance
 * shared secret. Fails closed when unconfigured; an unreachable Worker
 * throws, so a retention or erasure run stops rather than deleting from a
 * partial view.
 */
final class HttpArtifactGovernance implements ArtifactGovernanceContract
{
    private const PAGE_SIZE = 500;

    public function listArtifactsOlderThan(string $orgSlug, string $cutoffIso8601): array
    {
        return $this->listPages($orgSlug, ['older_than' => $cutoffIso8601]);
    }

    public function listAllArtifacts(string $orgSlug): array
    {
        return $this->listPages($orgSlug, []);
    }

    public function hardDeleteArtifact(string $orgSlug, string $artifactId): void
    {
        $response = $this->request()->delete($this->url($orgSlug, "artifacts/{$artifactId}"));

        if ($response->status() === 409) {
            throw new ArtifactUnderLegalHoldException($artifactId);
        }

        $response->throw();
    }

    public function placeLegalHold(string $orgSlug, string $artifactId): void
    {
        $this->request()->put($this->url($orgSlug, "artifacts/{$artifactId}/legal-hold"))->throw();
    }

    public function releaseLegalHold(string $orgSlug, string $artifactId): void
    {
        $this->request()->delete($this->url($orgSlug, "artifacts/{$artifactId}/legal-hold"))->throw();
    }

    /**
     * Deletes blobs no artifact references any more; returns how many.
     */
    public function sweepOrphans(string $orgSlug): int
    {
        return (int) $this->request()->post($this->url($orgSlug, 'sweep-orphans'))->throw()->json('removed');
    }

    /**
     * @param  array<string, string>  $query
     * @return array<int, array{id: string, created_at: string, legal_hold: bool}>
     */
    private function listPages(string $orgSlug, array $query): array
    {
        $artifacts = [];
        $cursor = null;

        do {
            $response = $this->request()->get($this->url($orgSlug, 'artifacts'), array_filter(
                $query + ['limit' => self::PAGE_SIZE, 'cursor' => $cursor],
                fn ($value) => $value !== null,
            ))->throw();

            foreach ($response->json('artifacts', []) as $artifact) {
                $artifacts[] = [
                    'id' => $artifact['id'],
                    'created_at' => $artifact['created_at'],
                    'legal_hold' => (bool) $artifact['legal_hold'],
                ];
            }

            $cursor = $response->json('next_cursor');
        } while ($cursor !== null);

        return $artifacts;
    }

    private function url(string $orgSlug, string $path): string
    {
        $baseUrl = config('services.worker.base_url');

        if (! $baseUrl) {
            throw new RuntimeException('services.worker.base_url must be configured to perform governance actions.');
        }

        return rtrim($baseUrl, '/')."/v1/internal/orgs/{$orgSlug}/governance/{$path}";
    }

    private function request(): PendingRequest
    {
        $secret = config('services.worker.governance_secret');

        if (! $secret) {
            throw new RuntimeException('services.worker.governance_secret must be configured to perform governance actions.');
        }

        return Http::withToken($secret)->acceptJson();
    }
}
