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

#[Description('Use when you have finished or skipped a set-up step the server cannot see, so Artfct onboarding can resume later. Call it only during set-up, after the person\'s consent, never for ordinary publish or search work. Steps from get_guidance (topic onboarding): read_usage_rules, explain_tool_approvals, save_memory_note (chat apps only; pass host) and ask_save_preference. Use done when you did it and skipped when the person declined or it did not apply. Pass a real host, not unknown, to get the next step back. The connection check, a saved preference and add_project_instructions cannot be recorded here.')]
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
