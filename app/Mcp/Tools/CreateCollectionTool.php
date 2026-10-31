<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\McpContext;
use App\Mcp\Support\McpErrorResponse;
use App\Mcp\Support\McpTelemetry;
use App\Mcp\Support\McpTimestamp;
use App\Models\User;
use App\Services\Collections\CollectionService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\UniqueConstraintViolationException;
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

#[Description('Create an organization-scoped artifact collection. Requires collections:write and never grants access to artifacts outside the workspace. New collections are not canonical (`canonical: false`); only an admin can pin one so its members get a search ranking boost.')]
#[Name('create_collection')]
#[Title('Create collection')]
#[IsReadOnly(false)]
#[IsIdempotent(false)]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
final class CreateCollectionTool extends Tool
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
                'description' => 'Create a named collection for related artifacts.',
                'arguments' => ['name' => 'Reporting formats'],
            ]],
        ],
    ];

    public function handle(Request $request, CollectionService $collections): Response|ResponseFactory
    {
        $startedAt = hrtime(true);
        McpContext::requireScope('collections:write', 'create_collection');
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $user = User::query()->findOrFail((int) McpContext::actor());
            $collection = $collections->create(
                McpContext::team(),
                $user,
                $validated['name'],
                $validated['description'] ?? null,
            );
        } catch (UniqueConstraintViolationException) {
            app(McpTelemetry::class)->record('create_collection', 'conflict', $startedAt);

            return McpErrorResponse::error('A collection with that name already exists in this workspace.', 'collection_already_exists');
        } catch (\Throwable $exception) {
            report($exception);
            app(McpTelemetry::class)->record('create_collection', 'error', $startedAt);

            return McpErrorResponse::error('The collection could not be created.', 'collection_create_failed');
        }

        app(McpTelemetry::class)->record('create_collection', 'success', $startedAt);

        return Response::structured([
            'id' => $collection->id,
            'name' => $collection->name,
            'description' => $collection->description,
            'canonical' => $collection->canonical,
            'artifact_count' => 0,
            'created_at' => McpTimestamp::format($collection->created_at),
            'updated_at' => McpTimestamp::format($collection->updated_at),
        ]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->min(1)->max(100)->description('Unique collection name within the workspace.')->required(),
            'description' => $schema->string()->max(500)->description('Optional collection description.')->nullable(),
        ];
    }
}
