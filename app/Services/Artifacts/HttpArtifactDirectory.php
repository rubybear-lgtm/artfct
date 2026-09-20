<?php

namespace App\Services\Artifacts;

use App\Contracts\ArtifactDirectory;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Http;

/**
 * Calls the Worker's org artifact endpoints (spec 08).
 * Authenticated with sessionJwt from the Laravel control plane.
 */
final class HttpArtifactDirectory implements ArtifactDirectory
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

    public function listArtifacts(
        string $orgSlug,
        array $filters = [],
        ?string $cursor = null,
        int $limit = 50,
    ): array {
        if (! $this->baseUrl) {
            return ['artifacts' => [], 'next_cursor' => null];
        }

        $params = ['limit' => $limit];
        if ($cursor) {
            $params['cursor'] = $cursor;
        }
        foreach ($filters as $key => $value) {
            if ($value !== null && $value !== '') {
                $params[$key] = $value;
            }
        }

        $response = Http::withToken($this->sessionJwt())
            ->get(rtrim($this->baseUrl, '/')."/v1/orgs/{$orgSlug}/artifacts", $params);

        if ($response->status() === 404) {
            throw new HttpResponseException(response()->json(['error' => 'Organization not found'], 404));
        }

        if ($response->status() === 403) {
            throw new HttpResponseException(response()->json(['error' => 'Forbidden'], 403));
        }

        return $response->json() ?: ['artifacts' => [], 'next_cursor' => null];
    }

    public function revokeArtifact(string $orgSlug, string $artifactId): array
    {
        if (! $this->baseUrl) {
            throw new \Exception('Worker base URL not configured');
        }

        $response = Http::withToken($this->sessionJwt())
            ->patch(
                rtrim($this->baseUrl, '/')."/v1/orgs/{$orgSlug}/artifacts/{$artifactId}",
                ['revoked_at' => now()->toIso8601String()]
            );

        if ($response->status() === 404) {
            throw new HttpResponseException(response()->json(['error' => 'Artifact not found'], 404));
        }

        if ($response->status() === 403) {
            throw new HttpResponseException(response()->json(['error' => 'Forbidden'], 403));
        }

        return $response->json() ?: [];
    }

    public function exportArtifacts(string $orgSlug): array
    {
        if (! $this->baseUrl) {
            throw new \Exception('Worker base URL not configured');
        }

        $response = Http::withToken($this->sessionJwt())
            ->get(rtrim($this->baseUrl, '/')."/v1/orgs/{$orgSlug}/export");

        if ($response->status() === 404) {
            throw new HttpResponseException(response()->json(['error' => 'Organization not found'], 404));
        }

        if ($response->status() === 403) {
            throw new HttpResponseException(response()->json(['error' => 'Forbidden'], 403));
        }

        if ($response->status() === 429) {
            throw new HttpResponseException(response()->json(['error' => 'Rate limited'], 429));
        }

        return $response->json() ?: ['artifacts' => [], 'blobs' => []];
    }

    /**
     * Get the session JWT for the authenticated user.
     * This would be implemented by the backend unit based on session context.
     */
    private function sessionJwt(): string
    {
        // A browser session carries no bearer token, so the console falls back
        // to the environment's org token. The Worker maps that token to its one
        // configured org; per-team credentials arrive with the multi-org Worker
        // (RUB-344).
        return request()->bearerToken() ?: (string) config('services.worker.org_token');
    }
}
