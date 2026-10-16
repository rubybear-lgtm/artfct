<?php

namespace App\Services\Slack;

use App\Models\ExternalIdentity;
use App\Models\Team;
use App\Services\Search\SearchService;

/**
 * `/artfct <query>` — "the human counterpart to spec 13's search_artifacts
 * MCP tool, same backend, same ranking." An unmapped Slack user gets a
 * connect prompt, never results — and a Slack user whose linked account
 * isn't actually a member of the org this workspace maps to gets the same
 * prompt, never another org's results (DoD: "A Slack user linked to org A
 * gets no results from org B").
 */
final class SlashCommandService
{
    public const PROVIDER = 'slack';

    public function __construct(private readonly SearchService $search) {}

    public function handle(string $slackWorkspaceId, string $slackUserId, string $query): SlashCommandResult
    {
        $team = Team::query()->where('slack_workspace_id', $slackWorkspaceId)->first();
        if ($team === null) {
            return SlashCommandResult::connectPrompt();
        }

        $identity = ExternalIdentity::query()
            ->where('provider', self::PROVIDER)
            ->where('external_id', $slackUserId)
            ->whereNotNull('verified_at')
            ->first();

        if ($identity === null) {
            return SlashCommandResult::connectPrompt();
        }

        $isMember = $team->memberships()->where('user_id', $identity->user_id)->exists();
        if (! $isMember) {
            return SlashCommandResult::connectPrompt();
        }

        $results = $this->search->search(
            $team,
            $query,
            filters: [],
            limit: 10,
            actor: (string) $identity->user_id,
            userAgent: 'slack-slash-command',
        );

        return SlashCommandResult::results($results);
    }
}
