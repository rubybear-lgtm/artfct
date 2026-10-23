<?php

namespace App\Mcp\Support;

use App\Services\Artifacts\ArtifactIdShape;
use Illuminate\Support\Facades\Http;
use Laravel\Mcp\Response;

/**
 * "Can this caller see this artifact?", answered by the Worker with the
 * caller's own token, the same rule get_artifact and read_artifact use. Anything
 * the caller cannot see, including a revoked artifact, reads as not found.
 */
final class McpArtifactAccess
{
    /**
     * @return Response|null null when the caller may see the artifact
     */
    public static function denyUnlessVisible(string $artifactId, string $tool, int $startedAt): ?Response
    {
        if (! ArtifactIdShape::isPermanent($artifactId)) {
            return McpErrorResponse::error('That is not an artifact id.', 'invalid_request');
        }

        $workerBaseUrl = config('services.worker.base_url');
        if (! is_string($workerBaseUrl) || $workerBaseUrl === '') {
            return McpErrorResponse::error('The artifact service is not configured.', 'configuration_error');
        }

        try {
            $response = Http::withToken(McpContext::httpRequest()->bearerToken())
                ->get(rtrim($workerBaseUrl, '/').'/v1/artifacts/'.$artifactId);
        } catch (\Throwable $exception) {
            report($exception);
            app(McpTelemetry::class)->record($tool, 'error', $startedAt);

            return McpErrorResponse::error('The artifact service is temporarily unavailable.', 'upstream_unavailable', true);
        }

        if (in_array($response->status(), [403, 404], true)) {
            app(McpTelemetry::class)->record($tool, 'not_found', $startedAt);

            return McpErrorResponse::error('That artifact was not found in the authenticated workspace.', 'artifact_not_found');
        }

        if (! $response->successful()) {
            app(McpTelemetry::class)->record($tool, 'error', $startedAt);

            return McpErrorResponse::error('The artifact service could not retrieve that artifact.', 'artifact_retrieval_failed', true);
        }

        return null;
    }
}
