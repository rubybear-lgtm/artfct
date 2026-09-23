<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\McpContext;
use App\Mcp\Support\McpErrorResponse;
use App\Mcp\Support\McpTelemetry;
use App\Services\Collections\CollectionDirectory;
use Illuminate\Contracts\JsonSchema\JsonSchema;
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

#[Description('List approved artifact collections in the authenticated workspace. Returns bounded collection metadata and an opaque cursor, never the collection contents.')]
#[Name('list_collections')]
#[IsReadOnly]
#[IsIdempotent]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
final class ListCollectionsTool extends Tool
{
    /** @var array<string, mixed> */
    protected ?array $meta = [
        'artfct' => [
            'contractVersion' => '1.0.0',
            'toolVersion' => '1.0.0',
            'owner' => 'artfct-mcp',
            'requiredScopes' => ['collections:read'],
            'compatibility' => 'stable',
            'examples' => [[
                'description' => 'List approved workspace collections.',
                'arguments' => ['limit' => 20],
            ]],
        ],
    ];

    /**
     * Handle the tool request.
     */
    public function handle(Request $request, CollectionDirectory $directory): Response|ResponseFactory
    {
        $startedAt = hrtime(true);
        McpContext::requireScope('collections:read', 'list_collections');

        $validated = $request->validate([
            'cursor' => ['nullable', 'string', 'max:512'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        try {
            $result = $directory->list(
                McpContext::team(),
                $validated['cursor'] ?? null,
                (int) ($validated['limit'] ?? 20),
            );
        } catch (\Throwable $exception) {
            report($exception);
            app(McpTelemetry::class)->record('list_collections', 'error', $startedAt);

            return McpErrorResponse::error('Workspace collections are temporarily unavailable.', 'upstream_unavailable', true);
        }

        app(McpTelemetry::class)->record('list_collections', 'success', $startedAt);

        return Response::structured($result);
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'cursor' => $schema->string()->max(512)->description('Opaque cursor returned by a previous page.')->nullable(),
            'limit' => $schema->integer()->description('Maximum collections to return, from 1 to 50.')->nullable(),
        ];
    }
}
