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

#[Description('Use when you need to create or replace one card, page or row on a board the bot can edit. Read first with db_get or db_list and pass if_version, the version you read (0 means the document must not exist yet). Needs data:write and edit access. The server sets version, updated_at and updated_by. Without if_version the write wins. For several writes that must succeed or fail together, use db_batch.')]
#[Name('db_set')]
#[Title('Write a database document')]
#[IsReadOnly(false)]
#[IsIdempotent]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
final class DbSetTool extends DatabaseTool
{
    /** @var array<string, mixed> */
    protected ?array $meta = [
        'artfct' => [
            'contractVersion' => '1.0.0',
            'guidanceVersion' => '1.11.0',
            'toolVersion' => '1.0.0',
            'owner' => 'artfct-mcp',
            'requiredScopes' => [
                'data:write',
            ],
            'compatibility' => 'experimental',
            'examples' => [
                [
                    'description' => 'Add a card.',
                    'arguments' => [
                        'id' => 'abc123def456x',
                        'collection' => 'cards',
                        'doc_id' => 'card-2',
                        'data' => [
                            'title' => 'Draft the spec',
                            'column' => 'todo',
                        ],
                    ],
                ],
            ],
        ],
    ];

    public function handle(Request $request): ResponseFactory
    {
        $startedAt = hrtime(true);
        McpContext::requireScope('data:write', 'db_set');
        $validated = $request->validate($this->targetRules() + [
            'data' => ['present', 'array'],
            'if_version' => ['nullable', 'integer', 'min:0'],
        ]);

        $refusal = $this->postDetailsRefusal($validated['collection'], $validated['doc_id'], $validated['data'], true);
        if ($refusal !== null) {
            return $this->refuseInvalidWrite('db_set', $startedAt, $refusal);
        }

        return $this->send('db_set', $startedAt, 'PUT', $validated['id'], $validated['collection'].'/'.$validated['doc_id'], [
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
            'doc_id' => $schema->string()->min(1)->max(128)->description('Document id: letters, digits, dot, underscore, hyphen.')->required(),
            'data' => $schema->object()->description('The document, a JSON object up to 64 KiB. Do not include version, updated_at or updated_by.')->required(),
            'if_version' => $schema->integer()->min(0)->description('The version you read; 0 means the document must not exist yet. Omit to overwrite.')->nullable(),
        ];
    }
}
