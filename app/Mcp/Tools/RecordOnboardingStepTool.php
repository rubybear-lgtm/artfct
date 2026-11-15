<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\ArtifactGuidance;
use App\Mcp\Support\McpContext;
use App\Mcp\Support\McpTelemetry;
use App\Models\User;
use App\Services\Analytics\ProductAnalytics;
use App\Services\Onboarding\OnboardingChecklist;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use InvalidArgumentException;
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

#[Description('Record that you finished or skipped one onboarding step the server cannot see for itself, so a set-up that stops can pick up where it left off and is never repeated. Call it after a step from get_guidance (topic onboarding): read_usage_rules, explain_tool_approvals, or ask_save_preference (only when the person chose not to answer). Use `done` when you did it and `skipped` when the person declined or it did not apply. Steps the server watches itself, such as the connection check and a saved preference, cannot be recorded, and neither can add_project_instructions, which is done once per project and checked in that project\'s own file. Pass a real `host` (not `unknown`) to also get the next step back.')]
#[Name('record_onboarding_step')]
#[Title('Record onboarding step')]
#[IsReadOnly(false)]
#[IsIdempotent(true)]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
final class RecordOnboardingStepTool extends Tool
{
    /** @var array<string, mixed>|null */
    protected ?array $meta = null;

    public function __construct()
    {
        $this->meta = [
            'artfct' => [
                'contractVersion' => '1.0.0',
                'toolVersion' => '1.0.0',
                'owner' => 'artfct-mcp',
                'requiredScopes' => [],
                'compatibility' => 'stable',
                'examples' => [[
                    'description' => 'Record that you read the usage rules during set-up.',
                    'arguments' => ['step' => 'read_usage_rules', 'outcome' => 'done', 'host' => 'claude-app'],
                ]],
            ],
        ];
    }

    public function handle(Request $request, OnboardingChecklist $checklist, ProductAnalytics $analytics): Response|ResponseFactory
    {
        $startedAt = hrtime(true);
        $validated = $request->validate([
            'step' => ['required', 'string', 'max:64'],
            'outcome' => ['required', 'string', 'max:16'],
            'host' => ['sometimes', 'nullable', 'string', 'max:32'],
        ]);

        $host = $validated['host'] ?? null;

        if ($host !== null && ! array_key_exists($host, ArtifactGuidance::HOST_MODES)) {
            return $this->fail('error', $startedAt, 'Unknown host. Allowed hosts: '.implode(', ', array_keys(ArtifactGuidance::HOST_MODES)).'.');
        }

        $actorId = McpContext::actor();
        $actor = ctype_digit($actorId) ? User::query()->find((int) $actorId) : null;

        if ($actor === null) {
            return $this->fail('denied', $startedAt, 'This connection is not signed in as a person or a bot teammate, so there is no set-up to record.');
        }

        try {
            $checklist->record($actor, $validated['step'], $validated['outcome'], $host);
        } catch (InvalidArgumentException $exception) {
            return $this->fail('error', $startedAt, $exception->getMessage());
        }

        $team = McpContext::team();
        $mode = $host === null ? null : ArtifactGuidance::HOST_MODES[$host];
        $progress = $mode === null || $mode === 'hybrid' ? null : $checklist->report($actor, $team, $mode);

        $analytics->onboardingStepRecorded(
            $actor,
            $team,
            $validated['step'],
            $validated['outcome'],
            $host,
            $progress['complete'] ?? false,
        );

        app(McpTelemetry::class)->record('record_onboarding_step', 'success', $startedAt);

        return Response::structured([
            'step' => $validated['step'],
            'outcome' => $validated['outcome'],
            'next_step' => $progress['next_step'] ?? null,
            'complete' => $progress['complete'] ?? null,
        ]);
    }

    private function fail(string $outcome, int $startedAt, string $message): Response
    {
        app(McpTelemetry::class)->record('record_onboarding_step', $outcome, $startedAt);

        return Response::error($message);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'step' => $schema->string()->enum(OnboardingChecklist::SELF_REPORTED)->description('The step you finished or skipped.')->required(),
            'outcome' => $schema->string()->enum(OnboardingChecklist::OUTCOMES)->description('`done` if you did it, `skipped` if the person declined or it did not apply.')->required(),
            'host' => $schema->string()->enum(array_keys(ArtifactGuidance::HOST_MODES))->description('The app you run in, the same value you passed to get_guidance. Pass it to get the next step back.')->nullable(),
        ];
    }
}
