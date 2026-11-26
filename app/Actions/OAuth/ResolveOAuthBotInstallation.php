<?php

namespace App\Actions\OAuth;

use App\Actions\Teams\CreateBotTeammate;
use App\Enums\TeamRole;
use App\Models\OAuthBotInstallation;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Resolve the bot teammate for an OAuth bot install (RUB-524, RUB-610).
 *
 * Create mode resolves by bot name: an existing team bot with that name is
 * reused (idempotent reconnect); a new name mints a new bot and a new
 * installation row for the shared OAuth client. Link mode binds the chosen
 * team bot without retargeting other installations on the same client.
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
            /** @var Collection<int, OAuthBotInstallation> $installations */
            $installations = OAuthBotInstallation::query()
                ->where('team_id', $team->id)
                ->where('oauth_client_id', $oauthClientId)
                ->lockForUpdate()
                ->get();

            $mode = $intent['mode'];
            if ($mode === 'create') {
                return $this->resolveCreate($team, $consenter, $oauthClientId, $intent['bot_name'], $installations);
            }

            if ($mode === 'link') {
                return $this->resolveLink($team, $consenter, $oauthClientId, $intent['bot_id'], $installations);
            }

            // Defensive: cached grants should only store create|link; anything
            // else must become invalid_grant rather than an uncaught error.
            throw ValidationException::withMessages([
                'bot_mode' => 'Choose whether to create a bot or link an existing one.',
            ]);
        });
    }

    /**
     * @param  Collection<int, OAuthBotInstallation>  $installations
     * @return array{bot: User, installation: OAuthBotInstallation, created: bool}
     */
    private function resolveCreate(
        Team $team,
        User $consenter,
        string $oauthClientId,
        ?string $botName,
        Collection $installations,
    ): array {
        $name = trim((string) $botName);
        if ($name === '' || mb_strlen($name) > 80) {
            throw ValidationException::withMessages([
                'bot_name' => 'Give the bot a name between 1 and 80 characters.',
            ]);
        }

        $existingBot = $this->findTeamBotByName($team, $name);
        if ($existingBot instanceof User) {
            return $this->bindExistingBot($team, $consenter, $oauthClientId, $existingBot, $installations);
        }

        $created = $this->createBotTeammate->handle($team, $consenter, $name, scopes: []);

        $installation = OAuthBotInstallation::query()->create([
            'team_id' => $team->id,
            'oauth_client_id' => $oauthClientId,
            'bot_user_id' => $created['bot']->id,
            'consented_by_user_id' => $consenter->id,
            'mcp_connection_id' => null,
        ]);

        return ['bot' => $created['bot'], 'installation' => $installation, 'created' => true];
    }

    /**
     * @param  Collection<int, OAuthBotInstallation>  $installations
     * @return array{bot: User, installation: OAuthBotInstallation, created: bool}
     */
    private function resolveLink(
        Team $team,
        User $consenter,
        string $oauthClientId,
        ?int $botId,
        Collection $installations,
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

        return $this->bindExistingBot($team, $consenter, $oauthClientId, $bot, $installations);
    }

    /**
     * @param  Collection<int, OAuthBotInstallation>  $installations
     * @return array{bot: User, installation: OAuthBotInstallation, created: bool}
     */
    private function bindExistingBot(
        Team $team,
        User $consenter,
        string $oauthClientId,
        User $bot,
        Collection $installations,
    ): array {
        $existing = $installations->first(
            fn (OAuthBotInstallation $installation): bool => $installation->bot_user_id === $bot->id,
        );

        if ($existing instanceof OAuthBotInstallation) {
            $existing->forceFill(['consented_by_user_id' => $consenter->id])->save();

            return ['bot' => $bot, 'installation' => $existing, 'created' => false];
        }

        $installation = OAuthBotInstallation::query()->create([
            'team_id' => $team->id,
            'oauth_client_id' => $oauthClientId,
            'bot_user_id' => $bot->id,
            'consented_by_user_id' => $consenter->id,
            'mcp_connection_id' => null,
        ]);

        return ['bot' => $bot, 'installation' => $installation, 'created' => false];
    }

    private function findTeamBotByName(Team $team, string $name): ?User
    {
        return $team->members()
            ->where('is_bot', true)
            ->where('users.name', $name)
            ->first();
    }
}
