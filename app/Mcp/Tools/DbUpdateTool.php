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

#[Description('Merge fields into an existing document in an artifact\'s database (a shallow merge: the keys in `data` replace those keys, everything else stays). The document must exist. Pass `if_version` to refuse the change if the document moved on. Needs the data:write scope and edit access to the artifact.')]
#[Name('db_update')]
#[Title('Update a database document')]
#[IsReadOnly(false)]
#[IsIdempotent(false)]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
final class DbUpdateTool extends DatabaseTool
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
                    'description' => 'Move a card to done.',
                    'arguments' => [
                        'id' => 'abc123def456x',
                        'collection' => 'cards',
                        'doc_id' => 'card-2',
                        'data' => [
                            'column' => 'done',
                        ],
                    ],
                ],
            ],
        ],
    ];

    public function handle(Request $request): ResponseFactory
    {
        $startedAt = hrtime(true);
        McpContext::requireScope('data:write', 'db_update');
        $validated = $request->validate($this->targetRules() + [
            'data' => ['required', 'array', 'min:1'],
            'if_version' => ['nullable', 'integer', 'min:0'],
        ]);

        return $this->send('db_update', $startedAt, 'PATCH', $validated['id'], $validated['collection'].'/'.$validated['doc_id'], [
            'if_version' => $validated['if_version'] ?? null,
        ], $validated['data']);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->min(1)->max(128)->description('Permanent artifact id.')->required(),
            'collection' => $schema->string()->min(1)->max(64)->description('A collection from db_schema.')->required(),
            'doc_id' => $schema->string()->min(1)->max(128)->description('Document id of an existing document.')->required(),
            'data' => $schema->object()->description('The fields to merge in. Keys here replace those keys; other keys stay.')->required(),
            'if_version' => $schema->integer()->min(0)->description('The version you read. Omit to merge into whatever is there.')->nullable(),
        ];
    }
}
