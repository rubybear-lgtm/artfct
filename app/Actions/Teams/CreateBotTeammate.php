<?php

namespace App\Actions\Teams;

use App\Enums\McpScope;
use App\Enums\OrgTokenKind;
use App\Enums\TeamRole;
use App\Models\McpConnection;
use App\Models\OrgToken;
use App\Models\Team;
use App\Models\User;
use App\Services\Auth\OrgJwtService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Adds a bot teammate to a team and optionally mints its first agent token.
 * Bots have no email or password; they authenticate only with Bearer tokens.
 *
 * @phpstan-type CreatedBot array{bot: User, token: ?string, org_token: ?OrgToken, expires_at: ?\Illuminate\Support\Carbon}
 */
class CreateBotTeammate
{
    /**
     * @param  list<string>|null  $scopes
     * @return CreatedBot
     */
    public function handle(
        Team $team,
        User $creator,
        string $name,
        ?array $scopes = null,
        ?int $ttlSeconds = null,
        ?string $avatar = null,
    ): array {
        $scopes ??= [
            McpScope::ArtifactsRead->value,
            McpScope::ArtifactsDeploy->value,
            McpScope::CollectionsRead->value,
            McpScope::UsageRead->value,
        ];
        $scopes = array_values(array_intersect(McpScope::forRole(TeamRole::Member), $scopes));
        $ttlSeconds ??= 31536000;

        return DB::transaction(function () use ($team, $creator, $name, $scopes, $ttlSeconds, $avatar): array {
            $bot = User::query()->create([
                'name' => $name,
                'is_bot' => true,
                'email' => null,
                'password' => null,
                'avatar' => $avatar,
                'email_verified_at' => null,
                'current_team_id' => $team->id,
            ]);

            $team->memberships()->create([
                'user_id' => $bot->id,
                'role' => TeamRole::Member,
            ]);

            if ($scopes === []) {
                return ['bot' => $bot, 'token' => null, 'org_token' => null, 'expires_at' => null];
            }

            $minted = OrgJwtService::default()->mint($team, $bot, TeamRole::Member, $ttlSeconds, $scopes);

            $connection = new McpConnection;
            $connection->forceFill([
                'public_id' => (string) Str::uuid(),
                'credential_jti' => $minted['jti'],
                'team_id' => $team->id,
                'user_id' => $bot->id,
                'name' => $name,
                'client_name' => $name,
                'transport' => 'streamable-http',
                'scopes' => $scopes,
                'expires_at' => $minted['expires_at'],
            ])->save();

            $orgToken = OrgToken::query()->create([
                'team_id' => $team->id,
                'user_id' => $bot->id,
                'mcp_connection_id' => $connection->id,
                'name' => $name,
                'kind' => OrgTokenKind::Agent,
                'jti' => $minted['jti'],
                'role' => TeamRole::Member,
                'scopes' => $scopes,
                'last_four' => Str::substr($minted['token'], -4),
                'expires_at' => $minted['expires_at'],
            ]);

            // Keep a breadcrumb of who added the bot without changing JWT actor.
            unset($creator);

            return [
                'bot' => $bot,
                'token' => $minted['token'],
                'org_token' => $orgToken,
                'expires_at' => $minted['expires_at'],
            ];
        });
    }
}
