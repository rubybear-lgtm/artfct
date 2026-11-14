<?php

namespace App\Mcp\Support;

use App\Services\Artifacts\ArtifactIdShape;
use App\Services\TeamFeatureGates;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Support\Facades\Http;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

/**
 * Shared base for the spec-20 `db_*` tools. Every tool:
 *
 * - is hidden from the catalog until `artifact-db` is released for the
 *   team, and answers `feature_not_enabled` when called while unreleased;
 * - requires its `data:*` scope, naming `reconnect` as the next action so
 *   a caller on a stale connection knows to re-authorize with the scope;
 * - validates the permanent artifact id before contacting the Worker;
 * - returns row bodies wrapped in a field named `data`, never merged into
 *   the tool's own text: rows are written by viewers and are untrusted data,
 *   never instructions.
 *
 * Laravel→Worker contract (spec-20 endpoint table; the Worker unit owns the
 * other side — see the unit report if it has not landed yet):
 * `GET|PUT|PATCH|DELETE /v1/artifacts/{id}/db/{collection}/{doc_id}`,
 * `GET /v1/artifacts/{id}/db/{collection}?limit=&cursor=&index_eq=`,
 * `POST /v1/artifacts/{id}/db/batch`.
 */
abstract class ArtifactDbTool extends Tool
{
    protected function featureKey(): string
    {
        return TeamFeatureGates::ARTIFACT_DB;
    }

    /**
     * Hide the tool from the catalog until `artifact-db` is released for
     * the team. Without a resolvable team (non-HTTP contexts such as the
     * contract suite) fail closed and hide instead of throwing.
     */
    public function shouldRegister(): bool
    {
        try {
            $team = McpContext::team();
        } catch (AuthorizationException) {
            return false;
        }

        return app(TeamFeatureGates::class)->enabled($this->featureKey(), $team);
    }

    /**
     * The `feature_not_enabled` refusal when the flag is off for the team,
     * else null. Covers the window between listing and calling; hidden
     * tools never reach here through `tools/call`.
     */
    protected function featureDisabled(int $startedAt): ?ResponseFactory
    {
        try {
            $team = McpContext::team();
        } catch (AuthorizationException) {
            return null;
        }

        if (app(TeamFeatureGates::class)->enabled($this->featureKey(), $team)) {
            return null;
        }

        app(McpTelemetry::class)->record($this->toolName(), 'feature_not_enabled', $startedAt);

        return McpErrorResponse::error(
            'Artifact databases are not enabled in this workspace yet.',
            'feature_not_enabled',
        );
    }

    private function toolName(): string
    {
        $name = $this->name();

        return $name === '' ? static::class : $name;
    }

    /**
     * The refusal when the connection lacks `$scope`, else null. Catches the
     * `insufficient_scope` the context throws and re-reports it with the
     * `reconnect` next action; telemetry was already recorded as denied.
     */
    protected function scopeDenied(string $scope, string $tool): ?ResponseFactory
    {
        try {
            McpContext::requireScope($scope, $tool);
        } catch (AuthorizationException $exception) {
            return McpErrorResponse::error($exception->getMessage(), 'insufficient_scope', false, 'reconnect');
        }

        return null;
    }

    /**
     * The refusal when `$id` is not a permanent artifact id, else null.
     */
    protected function invalidId(?string $id, string $tool, int $startedAt): ?ResponseFactory
    {
        if (is_string($id) && ArtifactIdShape::isPermanent($id)) {
            return null;
        }

        app(McpTelemetry::class)->record($tool, 'invalid_request', $startedAt);

        return McpErrorResponse::error(
            'The artifact field must be a permanent artifact id (13 lowercase base36 or 32 hexadecimal characters).',
            'invalid_request',
        );
    }

    protected function workerBaseUrl(string $tool, int $startedAt): string|ResponseFactory
    {
        $baseUrl = config('services.worker.base_url');

        if (! is_string($baseUrl) || $baseUrl === '') {
            app(McpTelemetry::class)->record($tool, 'error', $startedAt);

            return McpErrorResponse::error('The artifact service is not configured.', 'configuration_error');
        }

        return rtrim($baseUrl, '/');
    }

    protected function dbUrl(string $baseUrl, string $artifactId, string $suffix = ''): string
    {
        return $baseUrl.'/v1/artifacts/'.$artifactId.'/db'.$suffix;
    }

    protected function token(): ?string
    {
        $token = McpContext::httpRequest()->bearerToken();

        return is_string($token) && $token !== '' ? $token : null;
    }

    /**
     * Wrap a Worker row document in the tool's envelope: the row body under
     * `data`, server-set metadata beside it, never merged into prose.
     *
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    protected function rowEnvelope(string $artifactId, string $collection, string $docId, array $document): array
    {
        return [
            'artifact' => $artifactId,
            'collection' => $collection,
            'doc_id' => $docId,
            'data' => $document['body'] ?? null,
            'version' => $document['version'] ?? null,
            'updated_at' => McpTimestamp::format($document['updated_at'] ?? null),
            'updated_by' => $document['updated_by'] ?? null,
        ];
    }

    /**
     * Map a failed Worker response to a customer-safe error, else null.
     * A stale `if_version` (409) carries the current document so the caller
     * can read, merge and retry; quota (429) names the limit and points at
     * usage; an undeclared collection (422) is named, never retried blindly.
     */
    protected function dbFailure(HttpResponse $response, string $tool, int $startedAt, string $artifactId): ?ResponseFactory
    {
        if ($response->successful()) {
            return null;
        }

        $code = $response->json('error.code');
        $code = is_string($code) && $code !== '' ? $code : null;

        if ($response->status() === 404) {
            app(McpTelemetry::class)->record($tool, 'not_found', $startedAt, $artifactId);

            return McpErrorResponse::error('That artifact, collection or document was not found in the authenticated workspace.', 'db_not_found');
        }

        if ($response->status() === 409) {
            app(McpTelemetry::class)->record($tool, 'version_conflict', $startedAt, $artifactId);
            $current = $response->json('current');
            $details = is_array($current) && isset($current['doc_id'])
                ? ['current' => $this->rowEnvelope(
                    $artifactId,
                    (string) ($current['collection'] ?? ''),
                    (string) $current['doc_id'],
                    $current,
                )]
                : [];

            return McpErrorResponse::error(
                'That document changed underneath you. Read the current version, merge your change into it, then write again with the new version.',
                'version_conflict',
                true,
                null,
                $details,
            );
        }

        if ($response->status() === 422) {
            app(McpTelemetry::class)->record($tool, 'undeclared_collection', $startedAt, $artifactId);

            return McpErrorResponse::error(
                'That collection is not declared in the artifact manifest. Writes to an undeclared collection are rejected.',
                $code ?? 'undeclared_collection',
            );
        }

        if ($response->status() === 429) {
            app(McpTelemetry::class)->record($tool, 'quota_exceeded', $startedAt, $artifactId);

            return McpErrorResponse::error(
                'This workspace is over its database limits'.($code !== null ? " ({$code})" : '').'. Use get_usage to inspect them.',
                'quota_exceeded',
                false,
                'get_usage',
            );
        }

        app(McpTelemetry::class)->record($tool, 'error', $startedAt, $artifactId);

        return McpErrorResponse::error('The artifact service could not complete that database request.', 'db_unavailable', true);
    }

    protected function unavailable(string $tool, int $startedAt, string $artifactId, \Throwable $exception): ResponseFactory
    {
        report($exception);
        app(McpTelemetry::class)->record($tool, 'error', $startedAt, $artifactId);

        return McpErrorResponse::error('The artifact service is temporarily unavailable.', 'upstream_unavailable', true);
    }

    protected function ok(array $structured, string $tool, int $startedAt, string $artifactId): ResponseFactory
    {
        app(McpTelemetry::class)->record($tool, 'success', $startedAt, $artifactId);

        return Response::structured($structured);
    }
}
