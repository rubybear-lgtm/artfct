<?php

namespace App\Actions\OAuth;

use App\Actions\Teams\CreateBotTeammate;
use App\Enums\TeamRole;
use App\Models\OAuthBotInstallation;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Resolve the bot teammate for an OAuth bot install (RUB-524).
 *
 * Create mode is idempotent per (team, oauth_client_id): reconnects reuse the
 * existing bot instead of minting a duplicate. Link mode switches the binding
 * to an existing team bot. Deny/cancel never call this action, so no orphans.
 *
 * @phpstan-type BotInstallIntent array{mode: string, bot_name: ?string, bot_id: ?int}
 */
class ResolveOAuthBotInstallation
{
    public function __construct(private CreateBotTeammate $createBotTeammate) {}

    /**
     * @param  BotInstallIntent  $intent
     * @return array{bot: User, installation: OAuthBotInstallation, created: bool}
     */
    public function handle(
        Team $team,
        User $consenter,
        string $oauthClientId,
        array $intent,
    ): array {
        $role = $consenter->teamRole($team);
        if (! $role instanceof TeamRole || $role === TeamRole::Viewer || $consenter->isBot()) {
            throw ValidationException::withMessages([
                'bot_mode' => 'Only a human teammate with write access can connect a bot for this team.',
            ]);
        }

        return DB::transaction(function () use ($team, $consenter, $oauthClientId, $intent): array {
            $existing = OAuthBotInstallation::query()
                ->where('team_id', $team->id)
                ->where('oauth_client_id', $oauthClientId)
                ->lockForUpdate()
                ->first();

            $mode = $intent['mode'];
            if ($mode === 'create') {
                return $this->resolveCreate($team, $consenter, $oauthClientId, $intent['bot_name'], $existing);
            }

            if ($mode === 'link') {
                return $this->resolveLink($team, $consenter, $oauthClientId, $intent['bot_id'], $existing);
            }

            // Defensive: cached grants should only store create|link; anything
            // else must become invalid_grant rather than an uncaught error.
            throw ValidationException::withMessages([
                'bot_mode' => 'Choose whether to create a bot or link an existing one.',
            ]);
        });
    }

    /**
     * @return array{bot: User, installation: OAuthBotInstallation, created: bool}
     */
    private function resolveCreate(
        Team $team,
        User $consenter,
        string $oauthClientId,
        ?string $botName,
        ?OAuthBotInstallation $existing,
    ): array {
        if ($existing instanceof OAuthBotInstallation) {
            $bot = User::query()->find($existing->bot_user_id);
            if ($bot instanceof User && $bot->isBot() && $bot->belongsToTeam($team)) {
                $existing->forceFill(['consented_by_user_id' => $consenter->id])->save();

                return ['bot' => $bot, 'installation' => $existing, 'created' => false];
            }
        }

        $name = trim((string) $botName);
        if ($name === '' || mb_strlen($name) > 80) {
            throw ValidationException::withMessages([
                'bot_name' => 'Give the bot a name between 1 and 80 characters.',
            ]);
        }

        // Scopes are empty here: the OAuth token exchange mints the live
        // credential. CreateBotTeammate with [] still creates the bot member.
        $created = $this->createBotTeammate->handle($team, $consenter, $name, scopes: []);

        $installation = OAuthBotInstallation::query()->updateOrCreate(
            [
                'team_id' => $team->id,
                'oauth_client_id' => $oauthClientId,
            ],
            [
                'bot_user_id' => $created['bot']->id,
                'consented_by_user_id' => $consenter->id,
                'mcp_connection_id' => null,
            ],
        );

        return ['bot' => $created['bot'], 'installation' => $installation, 'created' => true];
    }

    /**
     * @return array{bot: User, installation: OAuthBotInstallation, created: bool}
     */
    private function resolveLink(
        Team $team,
        User $consenter,
        string $oauthClientId,
        ?int $botId,
        ?OAuthBotInstallation $existing,
    ): array {
        if ($botId === null || $botId < 1) {
            throw ValidationException::withMessages([
                'bot_id' => 'Choose an existing bot teammate to link.',
            ]);
        }

        $bot = User::query()->find($botId);
        if (! $bot instanceof User || ! $bot->isBot() || ! $bot->belongsToTeam($team)) {
            throw ValidationException::withMessages([
                'bot_id' => 'That bot is not on this team.',
            ]);
        }

        $installation = OAuthBotInstallation::query()->updateOrCreate(
            [
                'team_id' => $team->id,
                'oauth_client_id' => $oauthClientId,
            ],
            [
                'bot_user_id' => $bot->id,
                'consented_by_user_id' => $consenter->id,
                'mcp_connection_id' => $existing?->mcp_connection_id,
            ],
        );

        return ['bot' => $bot, 'installation' => $installation, 'created' => false];
    }
}
