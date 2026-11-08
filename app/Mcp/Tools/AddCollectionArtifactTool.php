<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\McpContext;
use App\Mcp\Support\McpErrorResponse;
use App\Mcp\Support\McpTelemetry;
use App\Models\Collection;
use App\Services\Collections\ArtifactExistence;
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

#[Description('Add an artifact to a workspace collection by collection id or name. Requires collections:write and is idempotent; it never changes artifact content or cross-organization visibility. You may pass collection_id, collection (name), or both; if both are given and they resolve to different collections, the call fails with a validation error and writes nothing. If both resolve to the same collection, it succeeds.')]
#[Name('add_collection_artifact')]
#[Title('Add artifact to collection')]
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
                'description' => 'Add an artifact to a collection by name.',
                'arguments' => ['collection' => 'Launch', 'artifact_id' => 'abc123'],
            ]],
        ],
    ];

    public function handle(Request $request, CollectionService $collections): ResponseFactory
    {
        $startedAt = hrtime(true);
        McpContext::requireScope('collections:write', 'add_collection_artifact');
        $validated = $request->validate([
            'collection_id' => ['nullable', 'integer', 'min:1', 'required_without:collection'],
            'collection' => ['nullable', 'string', 'max:100', 'required_without:collection_id'],
            'artifact_id' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9]+$/'],
        ]);

        $existence = app(ArtifactExistence::class)->check(McpContext::httpRequest()->bearerToken(), $validated['artifact_id']);

        if ($existence === ArtifactExistence::MISSING) {
            app(McpTelemetry::class)->record('add_collection_artifact', 'not_found', $startedAt);

            return McpErrorResponse::error('That artifact was not found in the authenticated workspace.', 'artifact_not_found');
        }

        if ($existence === ArtifactExistence::UNAVAILABLE) {
            app(McpTelemetry::class)->record('add_collection_artifact', 'error', $startedAt);

            return McpErrorResponse::error('The artifact service is temporarily unavailable.', 'upstream_unavailable', true);
        }

        $collection = $this->resolveCollection($collections, $validated);
        if ($collection instanceof ResponseFactory) {
            app(McpTelemetry::class)->record('add_collection_artifact', 'invalid_request', $startedAt);

            return $collection;
        }

        if ($collection === null) {
            app(McpTelemetry::class)->record('add_collection_artifact', 'collection_not_found', $startedAt);

            return McpErrorResponse::error('That collection was not found in the authenticated workspace. Pass its id or exact name from list_collections.', 'collection_not_found');
        }

        try {
            $collections->addArtifact($collection, $validated['artifact_id']);
        } catch (\Throwable $exception) {
            report($exception);
            app(McpTelemetry::class)->record('add_collection_artifact', 'error', $startedAt);

            return McpErrorResponse::error('The artifact could not be added to that collection.', 'collection_mutation_failed');
        }

        app(McpTelemetry::class)->record('add_collection_artifact', 'success', $startedAt);

        return Response::structured([
            'collection_id' => $collection->id,
            'collection' => $collection->name,
            'artifact_id' => $validated['artifact_id'],
        ]);
    }

    /**
     * @param  array{collection_id?: int|null, collection?: string|null, artifact_id: string}  $validated
     */
    private function resolveCollection(CollectionService $collections, array $validated): Collection|ResponseFactory|null
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
            'collection_id' => $schema->integer()->min(1)->description('Numeric collection id from list_collections or create_collection. Give this, `collection`, or both when they match. If both are given and disagree, the call fails without writing.')->nullable(),
            'collection' => $schema->string()->min(1)->max(100)->description('Collection name or numeric id (exact name match). Prefer the name when you have it. Give this, `collection_id`, or both when they match. If both are given and disagree, the call fails without writing.')->nullable(),
            'artifact_id' => $schema->string()->min(1)->max(128)->description('The artifact ID to add.')->required(),
        ];
    }
}
