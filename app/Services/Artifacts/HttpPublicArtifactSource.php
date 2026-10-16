<?php

namespace App\Services\Artifacts;

use App\Contracts\PublicArtifactSource;
use Illuminate\Support\Facades\Http;

/**
 * Calls the Worker's unauthenticated `GET /v1/public/artifacts/{id}` with no
 * credential at all: the endpoint serves a live public permanent artifact to
 * anyone and answers the same 404 for everything else. A 404 becomes null; any
 * other failure is thrown, never read as "not public", so a broken Worker
 * cannot silently turn a public artifact into a signed-in-only one.
 */
final class HttpPublicArtifactSource implements PublicArtifactSource
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

    public function find(string $artifactId): ?array
    {
        if (! $this->baseUrl) {
            throw new \RuntimeException('Worker base URL not configured');
        }

        $response = Http::acceptJson()
            ->get(rtrim($this->baseUrl, '/')."/v1/public/artifacts/{$artifactId}");

        if ($response->status() === 404) {
            return null;
        }

        $response->throw();

        $version = $response->json('version');
        $versionCount = $response->json('version_count');
        $title = $response->json('title');
        $description = $response->json('description');
        $updatedAt = $response->json('updated_at');
        $editAccess = $response->json('edit_access');

        return [
            'id' => (string) $response->json('id'),
            'org' => (string) $response->json('org'),
            'title' => is_string($title) ? $title : null,
            'description' => is_string($description) ? $description : null,
            'version' => is_numeric($version) ? (int) $version : 1,
            'version_count' => is_numeric($versionCount) ? (int) $versionCount : 1,
            'updated_at' => is_string($updatedAt) ? $updatedAt : null,
            'sharing' => 'public',
            'edit_access' => is_string($editAccess) ? $editAccess : null,
        ];
    }
}
