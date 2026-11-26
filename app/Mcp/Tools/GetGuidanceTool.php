<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\ArtifactGuidance;
use App\Mcp\Support\McpContext;
use App\Mcp\Support\McpErrorResponse;
use App\Models\User;
use App\Services\Onboarding\OnboardingChecklist;
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

#[Description('Use when you are about to publish, search, comment, use a board\'s data, pull a template or set up Artfct, and need the current rules for that task. Pass topic: publish, search, collaborate, data, onboarding, templates, kanban, team-wiki, announcements, context-post, team-doc or handoff. Pass host with onboarding, the app you run in. The rules apply even if you already know a shorter version. Call onboarding once after you connect: it also says which set-up steps are done or skipped and which is next.')]
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
            'guidanceVersion' => '1.15.0',
            'toolVersion' => '1.1.0',
            'owner' => 'artfct-mcp',
            'requiredScopes' => [],
            'compatibility' => 'stable',
            'examples' => [[
                'description' => 'Read the publishing rules before sharing a report.',
                'arguments' => ['topic' => 'publish'],
            ]],
        ],
    ];

    public function handle(Request $request, OnboardingChecklist $checklist): ResponseFactory
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

        // Where this person left off, so a set-up that stopped resumes and a
        // second run does not repeat finished steps. With no host the default
        // `portable` steps are tracked; only `unknown` (hybrid) has none yet.
        if ($validated['topic'] === 'onboarding' && ($guidance['mode'] ?? 'hybrid') !== 'hybrid') {
            $actorId = McpContext::actor();
            $actor = ctype_digit($actorId) ? User::query()->find((int) $actorId) : null;

            if ($actor !== null) {
                $guidance = [...$guidance, ...$checklist->report($actor, McpContext::team(), $guidance['mode'])];
            }
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
            'host' => $schema->string()->enum(array_keys(ArtifactGuidance::HOST_MODES))->description('Only for onboarding: the app you run in. Use portable, or leave it out, if you are not sure.'),
        ];
    }
}
