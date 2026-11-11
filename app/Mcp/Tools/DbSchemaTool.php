<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\McpContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('List the collections an artifact\'s database declares, with each collection\'s index field. Call this first: it tells you which collections exist before you read or write. Only artifacts published with a `db` section have one. Rules in get_guidance (topic data) apply.')]
#[Name('db_schema')]
#[Title('Show artifact database')]
#[IsReadOnly]
#[IsIdempotent]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
final class DbSchemaTool extends DatabaseTool
{
    /** @var array<string, mixed> */
    protected ?array $meta = [
        'artfct' => [
            'contractVersion' => '1.0.0',
            'guidanceVersion' => '1.1.0',
            'toolVersion' => '1.0.0',
            'owner' => 'artfct-mcp',
            'requiredScopes' => [
                'data:read',
            ],
            'compatibility' => 'experimental',
            'examples' => [
                [
                    'description' => 'See what a kanban artifact stores.',
                    'arguments' => [
                        'id' => 'abc123def456x',
                    ],
                ],
            ],
        ],
    ];

    public function handle(Request $request): ResponseFactory
    {
        $startedAt = hrtime(true);
        McpContext::requireScope('data:read', 'db_schema');
        $validated = $request->validate(['id' => ['required', 'string', 'max:128']]);

        return $this->send('db_schema', $startedAt, 'GET', $validated['id'], 'schema');
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->min(1)->max(128)->description('Permanent artifact id.')->required(),
        ];
    }
}
