<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\McpContext;
use App\Mcp\Support\McpErrorResponse;
use App\Mcp\Support\McpTelemetry;
use App\Services\Artifacts\ArtifactIdShape;
use App\Services\Collections\CollectionService;
use App\Services\Indexing\ArtifactTextPurger;
use App\Services\Indexing\IndexingService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Http;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Permanently delete one artifact from the authenticated workspace. Requires the explicit artifacts:delete scope and is refused while a legal hold is active.')]
#[Name('delete_artifact')]
#[Title('Delete artifact')]
#[IsReadOnly(false)]
#[IsIdempotent(true)]
#[IsDestructive(true)]
#[IsOpenWorld(false)]
final class DeleteArtifactTool extends Tool
{
    /** @var array<string, mixed> */
    protected ?array $meta = [
        'artfct' => [
            'contractVersion' => '1.0.0',
            'toolVersion' => '1.0.0',
            'owner' => 'artfct-mcp',
            'requiredScopes' => ['artifacts:delete'],
            'compatibility' => 'stable',
            'examples' => [[
                'description' => 'Permanently delete an artifact after confirming its ID.',
                'arguments' => ['id' => 'abc123'],
            ]],
        ],
    ];

    /**
     * Handle the tool request.
     */
    public function handle(Request $request, CollectionService $collections, IndexingService $indexer): Response|ResponseFactory
    {
        $startedAt = hrtime(true);
        McpContext::requireScope('artifacts:delete', 'delete_artifact');
        $validated = $request->validate([
            'id' => ['required', 'string', 'max:128'],
        ]);

        if (! ArtifactIdShape::isPermanent($validated['id']) && ! ArtifactIdShape::isEphemeral($validated['id'])) {
            app(McpTelemetry::class)->record('delete_artifact', 'invalid_request', $startedAt);

            return McpErrorResponse::error(
                'The id field must be a permanent artifact id (13 lowercase base36 or 32 hexadecimal characters) or a 10-character ephemeral artifact id.',
                'invalid_request',
            );
        }

        $workerBaseUrl = config('services.worker.base_url');
        if (! is_string($workerBaseUrl) || $workerBaseUrl === '') {
            app(McpTelemetry::class)->record('delete_artifact', 'error', $startedAt);

            return McpErrorResponse::error('The artifact service is not configured.', 'configuration_error');
        }

        try {
            $response = Http::withToken(McpContext::httpRequest()->bearerToken())
                ->delete(rtrim($workerBaseUrl, '/').'/v1/artifacts/'.$validated['id']);
        } catch (\Throwable $exception) {
            report($exception);
            app(McpTelemetry::class)->record('delete_artifact', 'error', $startedAt, $validated['id']);

            return McpErrorResponse::error('The artifact service is temporarily unavailable.', 'upstream_unavailable', true);
        }

        if ($response->status() === 404) {
            app(McpTelemetry::class)->record('delete_artifact', 'not_found', $startedAt, $validated['id']);

            return McpErrorResponse::error('That artifact was not found in the authenticated workspace.', 'artifact_not_found');
        }

        if ($response->status() === 409) {
            app(McpTelemetry::class)->record('delete_artifact', 'legal_hold', $startedAt, $validated['id']);

            return McpErrorResponse::error('That artifact is protected by a legal hold.', 'artifact_on_legal_hold');
        }

        if (! $response->successful()) {
            app(McpTelemetry::class)->record('delete_artifact', 'error', $startedAt, $validated['id']);

            return McpErrorResponse::error('The artifact service could not delete that artifact.', 'artifact_deletion_failed', $response->serverError());
        }

        // Drop memberships and the search index immediately so list_collections
        // and get_connection.search reflect the delete without waiting for the
        // Worker webhook (ArtifactDeletedHandler also purges).
        $team = McpContext::team();
        try {
            $collections->forgetArtifact($team, $validated['id']);
            $indexer->removeFromIndex($team, $validated['id']);
            ArtifactTextPurger::purgeEverything($team, $validated['id']);
        } catch (\Throwable $exception) {
            report($exception);
        }

        app(McpTelemetry::class)->record('delete_artifact', 'success', $startedAt, $validated['id']);

        return Response::structured([
            'id' => $validated['id'],
            'deleted' => true,
        ]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()
                ->min(1)
                ->max(128)
                ->description('The artifact ID returned by deploy_artifact or search_artifacts.')
                ->required(),
        ];
    }
}
