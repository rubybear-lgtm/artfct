<?php

namespace App\Services\Onboarding;

use App\Enums\PreferenceKey;
use App\Mcp\Support\ArtifactGuidance;
use App\Mcp\Support\McpTimestamp;
use App\Models\McpActivity;
use App\Models\OnboardingProgress;
use App\Models\Team;
use App\Models\User;
use App\Models\UserPreference;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Where a person is in the onboarding playbook, so a run can stop and resume
 * and the same run twice does nothing twice.
 *
 * A step is either seen by the server itself or reported by the person's own
 * agent. Server-seen steps are read from real data (MCP activity, the stored
 * preference), never stored, so they cannot drift from what happened. Only the
 * steps no server can see (the agent read the rules, it explained the tool
 * approvals) are stored, one row per person and step.
 *
 * A step that belongs to one project (the project instructions) is neither
 * stored nor counted. The server knows the person, not which repository they
 * are in, so remembering it per person would skip every project after the
 * first. The agent checks that project's own file instead, and the marker
 * block makes doing it again safe.
 *
 * What the server saw comes from MCP activity, which is pruned after its
 * retention window. A connection check or a first publish older than that is
 * not seen, so the step reads as pending until the agent checks again, which
 * costs one call and changes nothing.
 */
final class OnboardingChecklist
{
    /**
     * The steps an agent reports itself, in the one place that says so. A step
     * not listed here cannot be recorded, so a client cannot mark a step the
     * server watches for itself.
     *
     * @var list<string>
     */
    public const SELF_REPORTED = [
        'read_usage_rules',
        'ask_save_preference',
        'explain_tool_approvals',
    ];

    /**
     * Steps done once per project, not once per person. They are reported as
     * `per_project`, never stored, and never block `complete`.
     *
     * @var list<string>
     */
    public const PER_PROJECT = ['add_project_instructions'];

    /** @var list<string> */
    public const OUTCOMES = ['done', 'skipped'];

    /**
     * @return array{
     *     steps: list<array{id: string, requires_consent: bool, status: 'done'|'skipped'|'pending'|'per_project', source: 'server'|'agent'|null}>,
     *     next_step: string|null,
     *     complete: bool,
     *     milestones: array<string, array{done: bool, at: string|null}>
     * }
     */
    public function report(User $user, Team $team, string $mode): array
    {
        /** @var array<string, string> $reported */
        $reported = OnboardingProgress::query()->where('user_id', $user->id)->pluck('outcome', 'step')->all();
        $steps = [];

        foreach (ArtifactGuidance::stepsFor($mode) as $step) {
            [$status, $source] = $this->resolve($step['id'], $user, $team, $reported);
            $steps[] = [...$step, 'status' => $status, 'source' => $source];
        }

        $pending = array_values(array_filter($steps, static fn (array $step): bool => $step['status'] === 'pending'));

        return [
            'steps' => $steps,
            'next_step' => $pending[0]['id'] ?? null,
            'complete' => $steps !== [] && $pending === [],
            'milestones' => $this->milestones($user, $team),
        ];
    }

    /**
     * @throws InvalidArgumentException When the step is not one an agent reports, or the outcome is not allowed.
     */
    public function record(User $user, string $step, string $outcome, ?string $host): void
    {
        if (! in_array($step, self::SELF_REPORTED, true)) {
            throw new InvalidArgumentException(in_array($step, self::PER_PROJECT, true)
                ? 'That step is done once per project, so the server does not keep it. Check the project\'s own file instead.'
                : 'That step is watched by the server. Report one of: '.implode(', ', self::SELF_REPORTED).'.');
        }

        if (! in_array($outcome, self::OUTCOMES, true)) {
            throw new InvalidArgumentException('outcome must be one of: '.implode(', ', self::OUTCOMES).'.');
        }

        // Atomic, so an agent that reports the same step twice, or two agents
        // for the same person at once, never trips the unique index.
        OnboardingProgress::query()->upsert(
            [[
                'user_id' => $user->id,
                'step' => $step,
                'outcome' => $outcome,
                'host' => $host,
                'guidance_version' => ArtifactGuidance::VERSION,
            ]],
            ['user_id', 'step'],
            ['outcome', 'host', 'guidance_version'],
        );
    }

    /**
     * @param  array<string, string>  $reported
     * @return array{0: 'done'|'skipped'|'pending'|'per_project', 1: 'server'|'agent'|null}
     */
    private function resolve(string $step, User $user, Team $team, array $reported): array
    {
        if (in_array($step, self::PER_PROJECT, true)) {
            return ['per_project', null];
        }

        if ($step === 'verify_connection' && $this->firstSuccess($user, $team, 'get_connection') !== null) {
            return ['done', 'server'];
        }

        // A stored answer to the save question is the real thing: it is what
        // agents act on. Only a person who declined to say needs a report.
        if ($step === 'ask_save_preference' && $this->hasAnsweredSavePreference($user)) {
            return ['done', 'server'];
        }

        $outcome = $reported[$step] ?? null;

        if (in_array($step, self::SELF_REPORTED, true) && in_array($outcome, self::OUTCOMES, true)) {
            return [$outcome, 'agent'];
        }

        return ['pending', null];
    }

    private function hasAnsweredSavePreference(User $user): bool
    {
        $value = UserPreference::query()
            ->where('user_id', $user->id)
            ->where('key', PreferenceKey::SaveToArtfct->value)
            ->value('value');

        return is_string($value) && PreferenceKey::SaveToArtfct->accepts($value);
    }

    /**
     * What the server has seen this person do in this workspace. Measured from
     * MCP activity, so it needs no agent to report and cannot be made up.
     *
     * @return array<string, array{done: bool, at: string|null}>
     */
    private function milestones(User $user, Team $team): array
    {
        $milestones = [];

        foreach ([
            'connected' => null,
            'first_publish' => 'deploy_artifact',
            'first_search' => 'search_artifacts',
        ] as $name => $tool) {
            $at = $this->firstSuccess($user, $team, $tool);
            $milestones[$name] = ['done' => $at !== null, 'at' => $at];
        }

        return $milestones;
    }

    /** The time of the person's first successful call in this workspace, optionally of one tool. */
    private function firstSuccess(User $user, Team $team, ?string $tool): ?string
    {
        $at = McpActivity::query()
            ->where('team_id', $team->id)
            ->where('actor', (string) $user->id)
            ->where('outcome', 'success')
            ->when($tool !== null, static fn ($query) => $query->where('tool', $tool))
            ->orderBy('created_at')
            ->value('created_at');

        return McpTimestamp::format($at === null ? null : Carbon::parse($at));
    }
}
