<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\ArtifactGuidance;
use App\Mcp\Support\McpErrorResponse;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
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

#[Description('Return Artfct\'s rules for one task: publish (publishing and updating), search (finding and reading) or collaborate (safe updates and comments), with the current artifact types and limits. Read it before publishing or answering from the library. The rules apply even if you did not read them.')]
#[Name('get_guidance')]
#[IsReadOnly]
#[IsIdempotent]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
final class GetGuidanceTool extends Tool
{
    /** @var array<string, mixed> */
    protected ?array $meta = [
        'artfct' => [
            'contractVersion' => '1.0.0',
            'guidanceVersion' => '1.1.0',
            'toolVersion' => '1.0.0',
            'owner' => 'artfct-mcp',
            'requiredScopes' => [],
            'compatibility' => 'stable',
            'examples' => [[
                'description' => 'Read the publishing rules before sharing a report.',
                'arguments' => ['topic' => 'publish'],
            ]],
        ],
    ];

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'topic' => ['required', 'string', 'in:'.implode(',', ArtifactGuidance::TOPICS)],
        ]);

        $guidance = ArtifactGuidance::topic($validated['topic']);

        if ($guidance === null) {
            return McpErrorResponse::error('Unknown guidance topic.', 'invalid_request');
        }

        return Response::structured($guidance);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'topic' => $schema->string()->enum(ArtifactGuidance::TOPICS)->description('publish, search or collaborate.')->required(),
        ];
    }
}
