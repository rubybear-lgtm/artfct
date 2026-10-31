<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\McpContext;
use App\Mcp\Support\McpErrorResponse;
use App\Mcp\Support\McpTelemetry;
use App\Models\Collection;
use App\Services\Collections\CollectionService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
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

#[Description('Delete a workspace collection by id or name. Requires collections:write. Membership rows are removed with the collection; artifacts themselves are not deleted. You may pass collection_id, collection (name), or both; if both are given and they resolve to different collections, the call fails with a validation error and writes nothing.')]
#[Name('delete_collection')]
#[Title('Delete collection')]
#[IsReadOnly(false)]
#[IsIdempotent(true)]
#[IsDestructive(true)]
#[IsOpenWorld(false)]
final class DeleteCollectionTool extends Tool
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
                'description' => 'Delete a collection by name.',
                'arguments' => ['collection' => 'Scratch pad'],
            ]],
        ],
    ];

    public function handle(Request $request, CollectionService $collections): Response|ResponseFactory
    {
        $startedAt = hrtime(true);
        McpContext::requireScope('collections:write', 'delete_collection');
        $validated = $request->validate([
            'collection_id' => ['nullable', 'integer', 'min:1', 'required_without:collection'],
            'collection' => ['nullable', 'string', 'max:100', 'required_without:collection_id'],
        ]);

        $collection = $this->resolveCollection($collections, $validated);
        if ($collection instanceof Response) {
            app(McpTelemetry::class)->record('delete_collection', 'invalid_request', $startedAt);

            return $collection;
        }

        if ($collection === null) {
            app(McpTelemetry::class)->record('delete_collection', 'collection_not_found', $startedAt);

            return McpErrorResponse::error('That collection was not found in the authenticated workspace. Pass its id or exact name from list_collections.', 'collection_not_found');
        }

        $id = $collection->id;
        $name = $collection->name;

        try {
            $collections->delete($collection);
        } catch (\Throwable $exception) {
            report($exception);
            app(McpTelemetry::class)->record('delete_collection', 'error', $startedAt);

            return McpErrorResponse::error('The collection could not be deleted.', 'collection_delete_failed');
        }

        app(McpTelemetry::class)->record('delete_collection', 'success', $startedAt);

        return Response::structured([
            'collection_id' => $id,
            'collection' => $name,
            'deleted' => true,
        ]);
    }

    /**
     * @param  array{collection_id?: int, collection?: string}  $validated
     */
    private function resolveCollection(CollectionService $collections, array $validated): Collection|Response|null
    {
        $team = McpContext::team();
        $hasId = array_key_exists('collection_id', $validated) && $validated['collection_id'] !== null;
        $name = isset($validated['collection']) ? trim((string) $validated['collection']) : '';
        $hasName = $name !== '';

        if ($hasId && $hasName) {
            $byId = $collections->findForTeam($team, (string) $validated['collection_id']);
            $byName = $collections->findForTeam($team, $name);

            if ($byId === null || $byName === null || $byId->id !== $byName->id) {
                return McpErrorResponse::error(
                    'collection_id and collection refer to different collections. Pass one, or make sure both match.',
                    'invalid_request',
                );
            }

            return $byId;
        }

        $lookup = $hasId ? (string) $validated['collection_id'] : $name;

        return $collections->findForTeam($team, $lookup);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'collection_id' => $schema->integer()->min(1)->description('Numeric collection id from list_collections. Give this, `collection`, or both when they match.')->nullable(),
            'collection' => $schema->string()->min(1)->max(100)->description('Collection name or numeric id (exact name match). Give this, `collection_id`, or both when they match.')->nullable(),
        ];
    }
}
