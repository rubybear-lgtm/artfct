<?php

namespace App\Services\Artifacts;

use App\Contracts\ArtifactDirectory;
use App\Models\Team;
use App\Services\Auth\OrgJwtService;
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

        $response = Http::withToken($this->tokenFor($orgSlug))
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

        $response = Http::withToken($this->tokenFor($orgSlug))
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

        $response = Http::withToken($this->tokenFor($orgSlug))
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
     * The credential for a Worker call about `$orgSlug`: the caller's own
     * bearer token when the request carries one (API callers), otherwise a
     * short-lived org token minted for the signed-in user with their role in
     * that team. A user who is not a member of the team gets no credential, so
     * the Worker never sees a request about a team the user cannot access.
     */
    private function tokenFor(string $orgSlug): string
    {
        if ($bearer = request()->bearerToken()) {
            return $bearer;
        }

        $user = auth()->user();
        $team = Team::query()->where('slug', $orgSlug)->first();
        $role = $user !== null && $team !== null ? $user->teamRole($team) : null;

        if ($role === null) {
            throw new HttpResponseException(response()->json(['error' => 'Forbidden'], 403));
        }

        return OrgJwtService::default()->mintFor($orgSlug, (string) $user->id, $role)['token'];
    }
}
