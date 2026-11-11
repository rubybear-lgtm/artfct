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

#[Description('Apply up to 25 writes to an artifact\'s database together: every write is checked first and one failing write applies none. Each write is `set` (needs `data`) or `delete`, on a collection and doc_id, with an optional `if_version`. Use it to move several cards at once. Needs the data:write scope and edit access to the artifact.')]
#[Name('db_batch')]
#[Title('Write several database documents')]
#[IsReadOnly(false)]
#[IsIdempotent(false)]
#[IsDestructive]
#[IsOpenWorld(false)]
final class DbBatchTool extends DatabaseTool
{
    /** @var array<string, mixed> */
    protected ?array $meta = [
        'artfct' => [
            'contractVersion' => '1.0.0',
            'guidanceVersion' => '1.1.0',
            'toolVersion' => '1.0.0',
            'owner' => 'artfct-mcp',
            'requiredScopes' => [
                'data:write',
            ],
            'compatibility' => 'experimental',
            'examples' => [
                [
                    'description' => 'Move two cards in one call.',
                    'arguments' => [
                        'id' => 'abc123def456x',
                        'writes' => [
                            [
                                'op' => 'set',
                                'collection' => 'cards',
                                'doc_id' => 'card-1',
                                'data' => [
                                    'column' => 'done',
                                ],
                            ],
                            [
                                'op' => 'set',
                                'collection' => 'cards',
                                'doc_id' => 'card-2',
                                'data' => [
                                    'column' => 'done',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];

    public function handle(Request $request): ResponseFactory
    {
        $startedAt = hrtime(true);
        McpContext::requireScope('data:write', 'db_batch');
        $validated = $request->validate([
            'id' => ['required', 'string', 'max:128'],
            'writes' => ['required', 'array', 'min:1', 'max:25'],
            'writes.*.op' => ['required', 'string', 'in:set,delete'],
            'writes.*.collection' => [...self::NAME_RULE, 'max:64'],
            'writes.*.doc_id' => [...self::NAME_RULE, 'max:128'],
            'writes.*.data' => ['nullable', 'array'],
            'writes.*.if_version' => ['nullable', 'integer', 'min:0'],
        ]);

        $writes = array_map(static function (array $write): array {
            $write = array_filter($write, static fn ($value): bool => $value !== null);
            if (isset($write['data']) && $write['data'] === []) {
                $write['data'] = new \stdClass;
            }

            return $write;
        }, $validated['writes']);

        return $this->send('db_batch', $startedAt, 'POST', $validated['id'], 'batch', [], ['writes' => $writes]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->min(1)->max(128)->description('Permanent artifact id.')->required(),
            'writes' => $schema->array()->items($schema->object([
                'op' => $schema->string()->enum(['set', 'delete'])->required(),
                'collection' => $schema->string()->min(1)->max(64)->required(),
                'doc_id' => $schema->string()->min(1)->max(128)->required(),
                'data' => $schema->object()->description('The document, for set.')->nullable(),
                'if_version' => $schema->integer()->min(0)->nullable(),
            ]))->min(1)->max(25)->description('The writes, applied together.')->required(),
        ];
    }
}
