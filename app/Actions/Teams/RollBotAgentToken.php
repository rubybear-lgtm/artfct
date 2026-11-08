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
use App\Services\Auth\OrgTokenRevoker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Revokes a bot's active agent tokens and mints a replacement shown once.
 *
 * @phpstan-type RolledBotToken array{bot: User, token: string, org_token: OrgToken, expires_at: \Illuminate\Support\Carbon, revoked_count: int}
 */
class RollBotAgentToken
{
    public function __construct(private OrgTokenRevoker $tokenRevoker) {}

    /**
     * @param  list<string>|null  $scopes
     * @return RolledBotToken
     */
    public function handle(
        Team $team,
        User $bot,
        ?array $scopes = null,
        ?int $ttlSeconds = null,
    ): array {
        abort_unless($bot->is_bot && $bot->belongsToTeam($team), 404);

        $activeTokens = OrgToken::query()
            ->where('team_id', $team->id)
            ->where('user_id', $bot->id)
            ->where('kind', OrgTokenKind::Agent)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('id')
            ->get();

        $scopes ??= $activeTokens->first()?->scopes;
        $scopes ??= [
            McpScope::ArtifactsRead->value,
            McpScope::ArtifactsDeploy->value,
            McpScope::CollectionsRead->value,
            McpScope::UsageRead->value,
        ];
        $scopes = array_values(array_intersect(McpScope::forRole(TeamRole::Member), $scopes));
        abort_if($scopes === [], 422, __('Choose at least one permission for this agent token.'));

        $ttlSeconds ??= 31536000;

        if ($activeTokens->isNotEmpty() && ! $this->tokenRevoker->denylistTokens($activeTokens)) {
            throw new RuntimeException(__('Unable to revoke this bot’s active credentials. Please retry shortly.'));
        }

        $revokedAt = now();
        $minted = OrgJwtService::default()->mint($team, $bot, TeamRole::Member, $ttlSeconds, $scopes);

        $orgToken = DB::transaction(function () use ($team, $bot, $scopes, $minted, $activeTokens, $revokedAt): OrgToken {
            foreach ($activeTokens as $token) {
                $token->forceFill(['revoked_at' => $revokedAt])->save();
            }

            if ($activeTokens->isNotEmpty()) {
                McpConnection::query()
                    ->where('team_id', $team->id)
                    ->where('user_id', $bot->id)
                    ->whereNull('revoked_at')
                    ->whereIn('credential_jti', $activeTokens->pluck('jti')->all())
                    ->update(['revoked_at' => $revokedAt]);
            }

            $connection = new McpConnection;
            $connection->forceFill([
                'public_id' => (string) Str::uuid(),
                'credential_jti' => $minted['jti'],
                'team_id' => $team->id,
                'user_id' => $bot->id,
                'name' => $bot->name,
                'client_name' => $bot->name,
                'transport' => 'streamable-http',
                'scopes' => $scopes,
                'expires_at' => $minted['expires_at'],
            ])->save();

            return OrgToken::query()->create([
                'team_id' => $team->id,
                'user_id' => $bot->id,
                'mcp_connection_id' => $connection->id,
                'name' => $bot->name,
                'kind' => OrgTokenKind::Agent,
                'jti' => $minted['jti'],
                'role' => TeamRole::Member,
                'scopes' => $scopes,
                'last_four' => Str::substr($minted['token'], -4),
                'expires_at' => $minted['expires_at'],
            ]);
        });

        return [
            'bot' => $bot,
            'token' => $minted['token'],
            'org_token' => $orgToken,
            'expires_at' => $minted['expires_at'],
            'revoked_count' => $activeTokens->count(),
        ];
    }
}
