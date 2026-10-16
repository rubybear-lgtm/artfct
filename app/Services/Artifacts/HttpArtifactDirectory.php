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

    public function getArtifact(string $orgSlug, string $artifactId): ?array
    {
        if (! $this->baseUrl) {
            return null;
        }

        $response = Http::withToken($this->tokenFor($orgSlug))
            ->get(rtrim($this->baseUrl, '/')."/v1/artifacts/{$artifactId}");

        // A missing artifact, another org's artifact, and a private artifact
        // the caller may not see are one 404, so the caller cannot tell them
        // apart and the page is not an existence oracle.
        if ($response->status() === 404) {
            return null;
        }

        if ($response->status() === 403) {
            throw new HttpResponseException(response()->json(['error' => 'Forbidden'], 403));
        }

        $response->throw();

        return $response->json() ?: null;
    }

    public function updateSharing(string $orgSlug, string $artifactId, array $changes): array
    {
        if (! $this->baseUrl) {
            return ['status' => 'not_found', 'sharing' => null, 'edit_access' => null];
        }

        $response = Http::withToken($this->tokenFor($orgSlug))
            ->patch(rtrim($this->baseUrl, '/')."/v1/artifacts/{$artifactId}/sharing", $changes);

        if ($response->successful()) {
            return [
                'status' => 'updated',
                'sharing' => $response->json('sharing'),
                'edit_access' => $response->json('edit_access'),
            ];
        }

        return match ($response->status()) {
            // `public_sharing_disabled` is the Worker's distinct refusal for a
            // team that turned public links off, carried as the error code.
            403 => [
                'status' => $response->json('error.code') === 'public_sharing_disabled' ? 'public_disabled' : 'forbidden',
                'sharing' => null,
                'edit_access' => null,
            ],
            404 => ['status' => 'not_found', 'sharing' => null, 'edit_access' => null],
            default => throw new HttpResponseException(response()->json(['error' => 'Artifact service error'], 502)),
        };
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

    public function fetchBlob(string $orgSlug, string $sha256): ?string
    {
        if (! $this->baseUrl) {
            throw new \Exception('Worker base URL not configured');
        }

        if (preg_match('/^[0-9a-f]{64}$/', $sha256) !== 1) {
            return null;
        }

        $response = Http::withToken($this->tokenFor($orgSlug))
            ->get(rtrim($this->baseUrl, '/')."/v1/blobs/{$sha256}");

        if ($response->status() === 404) {
            return null;
        }

        if ($response->status() === 403) {
            throw new HttpResponseException(response()->json(['error' => 'Forbidden'], 403));
        }

        $response->throw();

        return $response->body();
    }

    public function listVersions(string $orgSlug, string $artifactId): ?array
    {
        if (! $this->baseUrl) {
            return null;
        }

        $response = Http::withToken($this->tokenFor($orgSlug))
            ->get(rtrim($this->baseUrl, '/')."/v1/artifacts/{$artifactId}/versions");

        // Another organization's artifact is the same 404 as a missing one, so
        // the page can render its not-found state without an existence oracle.
        if ($response->status() === 404) {
            return null;
        }

        if ($response->status() === 403) {
            throw new HttpResponseException(response()->json(['error' => 'Forbidden'], 403));
        }

        $response->throw();

        return $response->json() ?: null;
    }

    public function restoreVersion(string $orgSlug, string $artifactId, int $version): array
    {
        if (! $this->baseUrl) {
            return ['status' => 'not_found', 'version' => null];
        }

        $response = Http::withToken($this->tokenFor($orgSlug))
            ->post(rtrim($this->baseUrl, '/')."/v1/artifacts/{$artifactId}/versions/{$version}/restore");

        if ($response->successful()) {
            return [
                'status' => $response->json('created') ? 'restored' : 'unchanged',
                'version' => is_numeric($response->json('version')) ? (int) $response->json('version') : null,
            ];
        }

        return match ($response->status()) {
            403 => ['status' => 'forbidden', 'version' => null],
            404 => ['status' => 'not_found', 'version' => null],
            409 => ['status' => 'conflict', 'version' => null],
            default => throw new HttpResponseException(response()->json(['error' => 'Artifact service error'], 502)),
        };
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
