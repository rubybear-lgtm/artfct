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
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Return Artfct\'s rules for one task: publish (publishing and updating), search (finding and reading), collaborate (safe updates and comments), data (the artifact database) or onboarding (set-up steps for the app you run in, passed as host), with the current artifact types and limits. Read it before publishing or answering from the library, and call onboarding once after you connect. The rules apply even if you did not read them.')]
#[Name('get_guidance')]
#[Title('Get guidance')]
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
            'guidanceVersion' => '1.2.0',
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

    public function handle(Request $request): ResponseFactory
    {
        // Accept any string first so an unknown topic can list the allowed
        // values instead of Laravel's generic `in:` validation message.
        $validated = $request->validate([
            'topic' => ['required', 'string', 'max:100'],
            'host' => ['nullable', 'string', 'max:100'],
        ]);

        $host = $validated['host'] ?? null;

        if ($validated['topic'] === 'onboarding' && $host !== null && ! array_key_exists($host, ArtifactGuidance::HOST_MODES)) {
            return McpErrorResponse::error(
                'Unknown host. Allowed hosts: '.implode(', ', array_keys(ArtifactGuidance::HOST_MODES)).'.',
                'invalid_request',
            );
        }

        $guidance = ArtifactGuidance::topic($validated['topic'], $host);

        if ($guidance === null) {
            return McpErrorResponse::error(
                'Unknown guidance topic. Allowed topics: '.implode(', ', ArtifactGuidance::TOPICS).'.',
                'invalid_request',
            );
        }

        return Response::structured($guidance);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'topic' => $schema->string()->enum(ArtifactGuidance::TOPICS)->description('publish, search, collaborate, data or onboarding.')->required(),
            'host' => $schema->string()->enum(array_keys(ArtifactGuidance::HOST_MODES))->description('Only for onboarding: the app you run in. Use unknown, or leave it out, if you are not sure.'),
        ];
    }
}
