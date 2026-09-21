<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\McpContext;
use App\Mcp\Support\McpErrorResponse;
use App\Mcp\Support\McpTelemetry;
use App\Models\Collection;
use App\Services\Collections\CollectionService;
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

#[Description('Add an artifact to an organization-scoped collection. Requires collections:write and is idempotent; it never changes artifact content or cross-organization visibility.')]
#[Name('add_collection_artifact')]
#[IsReadOnly(false)]
#[IsIdempotent]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
final class AddCollectionArtifactTool extends Tool
{
    /** @var array<string, mixed> */
    protected ?array $meta = [
        'artfct' => [
            'contractVersion' => '1.0.0',
            'toolVersion' => '1.0.0',
            'owner' => 'artfct-mcp',
            'requiredScopes' => ['collections:write'],
            'compatibility' => 'stable',
            'examples' => [[
                'description' => 'Add an artifact to an approved collection.',
                'arguments' => ['collection_id' => 1, 'artifact_id' => 'abc123'],
            ]],
        ],
    ];

    public function handle(Request $request, CollectionService $collections): Response|ResponseFactory
    {
        $startedAt = hrtime(true);
        McpContext::requireScope('collections:write', 'add_collection_artifact');
        $validated = $request->validate([
            'collection_id' => ['required', 'integer', 'min:1'],
            'artifact_id' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9]+$/'],
        ]);

        try {
            $collection = Collection::query()
                ->where('team_id', McpContext::team()->id)
                ->findOrFail($validated['collection_id']);
            $collections->addArtifact($collection, $validated['artifact_id']);
        } catch (\Throwable $exception) {
            report($exception);
            app(McpTelemetry::class)->record('add_collection_artifact', 'error', $startedAt);

            return McpErrorResponse::error('The artifact could not be added to that collection.', 'collection_mutation_failed');
        }

        app(McpTelemetry::class)->record('add_collection_artifact', 'success', $startedAt);

        return Response::structured([
            'collection_id' => $collection->id,
            'artifact_id' => $validated['artifact_id'],
        ]);
    }

    /** @return array<string, JsonSchema> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'collection_id' => $schema->integer()->min(1)->description('The collection ID returned by list_collections or create_collection.')->required(),
            'artifact_id' => $schema->string()->min(1)->max(128)->description('The artifact ID to add.')->required(),
        ];
    }
}
