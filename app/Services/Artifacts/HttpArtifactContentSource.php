<?php

namespace App\Services\Artifacts;

use App\Contracts\ArtifactContentSource;
use App\Enums\TeamRole;
use App\Services\Auth\OrgJwtService;
use Illuminate\Support\Facades\Http;

/**
 * Calls the Worker's `GET /v1/orgs/{org}/artifacts/{id}/content` with the
 * org token (no user session: this runs from the worker-event handler).
 */
final class HttpArtifactContentSource implements ArtifactContentSource
{
    public function __construct(
        private readonly ?string $baseUrl = null,
    ) {}

    public static function default(): self
    {
        return new self(
            config('services.worker.base_url') ?: null,
        );
    }

    public function fetch(string $orgSlug, string $artifactId): ?array
    {
        if (! $this->baseUrl) {
            throw new \RuntimeException('Worker base URL not configured');
        }

        // Server-side read (no signed-in user): a short-lived system token for the org.
        $token = OrgJwtService::default()->mintFor($orgSlug, 'system', TeamRole::Member)['token'];

        $response = Http::withToken($token)
            ->get(rtrim($this->baseUrl, '/')."/v1/orgs/{$orgSlug}/artifacts/{$artifactId}/content");

        if ($response->status() === 404) {
            return null;
        }

        $response->throw();

        return [
            'html' => (string) $response->json('content'),
            'provenance' => [
                'agent' => $response->json('provenance.agent'),
                'repo_url' => $response->json('provenance.repo_url'),
                'commit_sha' => $response->json('provenance.commit_sha'),
            ],
        ];
    }
}
