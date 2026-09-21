<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\McpContext;
use App\Mcp\Support\McpErrorResponse;
use App\Mcp\Support\McpTelemetry;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Http;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Retrieve safe metadata for one artifact in the authenticated workspace. Returns lifecycle and provenance-adjacent metadata, never the HTML bundle.')]
#[Name('get_artifact')]
#[IsReadOnly]
#[IsIdempotent]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
final class GetArtifactTool extends Tool
{
    /** @var array<string, mixed> */
    protected ?array $meta = [
        'artfct' => [
            'contractVersion' => '1.0.0',
            'toolVersion' => '1.0.0',
            'owner' => 'artfct-mcp',
            'requiredScopes' => ['artifacts:read'],
            'compatibility' => 'stable',
            'examples' => [[
                'description' => 'Read safe metadata for one artifact.',
                'arguments' => ['id' => 'abc123'],
            ]],
        ],
    ];

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $startedAt = hrtime(true);
        McpContext::requireScope('artifacts:read', 'get_artifact');
        $validated = $request->validate([
            'id' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9]+$/'],
        ]);

        $workerBaseUrl = config('services.worker.base_url');
        if (! is_string($workerBaseUrl) || $workerBaseUrl === '') {
            app(McpTelemetry::class)->record('get_artifact', 'error', $startedAt);

            return McpErrorResponse::error('The artifact service is not configured.', 'configuration_error');
        }

        try {
            $response = Http::withToken(McpContext::httpRequest()->bearerToken())
                ->get(rtrim($workerBaseUrl, '/').'/v1/artifacts/'.$validated['id']);
        } catch (\Throwable $exception) {
            report($exception);
            app(McpTelemetry::class)->record('get_artifact', 'error', $startedAt);

            return McpErrorResponse::error('The artifact service is temporarily unavailable.', 'upstream_unavailable', true);
        }

        if ($response->status() === 404) {
            app(McpTelemetry::class)->record('get_artifact', 'not_found', $startedAt);

            return McpErrorResponse::error('That artifact was not found in the authenticated workspace.', 'artifact_not_found');
        }

        if (! $response->successful()) {
            app(McpTelemetry::class)->record('get_artifact', 'error', $startedAt);

            return McpErrorResponse::error('The artifact service could not retrieve that artifact.', 'artifact_retrieval_failed', true);
        }

        app(McpTelemetry::class)->record('get_artifact', 'success', $startedAt, $validated['id']);

        return Response::structured([
            'id' => $response->json('id'),
            'tier' => $response->json('tier'),
            'entrypoint' => $response->json('entrypoint'),
            'created_at' => $response->json('created_at'),
            'expires_at' => $response->json('expires_at'),
            'title' => $response->json('title'),
            'description' => $response->json('description'),
        ]);
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()
                ->min(1)
                ->max(128)
                ->description('The artifact ID returned by deploy_to_canvas or search_artifacts.')
                ->required(),
        ];
    }
}
