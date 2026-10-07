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
        // Indexing must see private artifacts, so the token carries the same
        // explicit `artifacts:read_private` scope a private-tier read needs;
        // the Private rule itself is applied by each caller that shows or
        // links what this returns (`ArtifactVisibility`).
        $token = OrgJwtService::default()
            ->mintFor($orgSlug, 'system', TeamRole::Member, scopes: ['artifacts:read', 'artifacts:read_private'])['token'];

        $response = Http::withToken($token)
            ->get(rtrim($this->baseUrl, '/')."/v1/orgs/{$orgSlug}/artifacts/{$artifactId}/content");

        if ($response->status() === 404) {
            return null;
        }

        $response->throw();

        $version = $response->json('version');
        $sharing = $response->json('sharing');
        $ownerUserId = $response->json('owner_user_id');

        return [
            'html' => (string) $response->json('content'),
            'version' => is_numeric($version) ? (int) $version : null,
            // The Worker's `public`/`secure` tier. The open route reads it to
            // decide whether there is a token to mint at all, so it travels
            // with the content rather than costing a second round trip.
            'tier' => $response->json('tier'),
            // The Worker's sharing level and owner. Server-side callers apply
            // the Private rule themselves (`ArtifactVisibility`); an absent or
            // unknown value fails closed to private there.
            'sharing' => is_string($sharing) ? $sharing : null,
            'owner_user_id' => is_string($ownerUserId) ? $ownerUserId : null,
            'provenance' => [
                'agent' => $response->json('provenance.agent'),
                'repo_url' => $response->json('provenance.repo_url'),
                'commit_sha' => $response->json('provenance.commit_sha'),
            ],
        ];
    }
}
