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

#[Description('Delete one document from an artifact\'s database. Deleting a document that is already gone succeeds with deleted false. Pass `if_version` to refuse the delete if the document changed. Needs the data:write scope and edit access to the artifact.')]
#[Name('db_delete')]
#[Title('Delete a database document')]
#[IsReadOnly(false)]
#[IsIdempotent]
#[IsDestructive]
#[IsOpenWorld(false)]
final class DbDeleteTool extends DatabaseTool
{
    /** @var array<string, mixed> */
    protected ?array $meta = [
        'artfct' => [
            'contractVersion' => '1.0.0',
            'guidanceVersion' => '1.2.0',
            'toolVersion' => '1.0.0',
            'owner' => 'artfct-mcp',
            'requiredScopes' => [
                'data:write',
            ],
            'compatibility' => 'experimental',
            'examples' => [
                [
                    'description' => 'Remove a card.',
                    'arguments' => [
                        'id' => 'abc123def456x',
                        'collection' => 'cards',
                        'doc_id' => 'card-2',
                    ],
                ],
            ],
        ],
    ];

    public function handle(Request $request): ResponseFactory
    {
        $startedAt = hrtime(true);
        McpContext::requireScope('data:write', 'db_delete');
        $validated = $request->validate($this->targetRules() + [
            'if_version' => ['nullable', 'integer', 'min:0'],
        ]);

        return $this->send('db_delete', $startedAt, 'DELETE', $validated['id'], $validated['collection'].'/'.$validated['doc_id'], [
            'if_version' => $validated['if_version'] ?? null,
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->min(1)->max(128)->description('Permanent artifact id.')->required(),
            'collection' => $schema->string()->min(1)->max(64)->description('A collection from db_schema.')->required(),
            'doc_id' => $schema->string()->min(1)->max(128)->description('Document id.')->required(),
            'if_version' => $schema->integer()->min(0)->description('The version you read. Omit to delete regardless.')->nullable(),
        ];
    }
}
