<?php

namespace App\Services\Auth;

use App\Models\McpConnection;
use App\Models\OAuthRefreshToken;
use App\Models\OrgToken;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class OrgTokenRevoker
{
    public function revokeForMember(Team $team, User $user): bool
    {
        $tokens = OrgToken::query()
            ->where('team_id', $team->id)
            ->where('user_id', $user->id)
            ->where('expires_at', '>', now())
            ->get();
        $revokedAt = now();

        if (! $this->denylistTokens($tokens)) {
            return false;
        }

        DB::transaction(function () use ($team, $user, $tokens, $revokedAt): void {
            foreach ($tokens as $token) {
                $token->forceFill(['revoked_at' => $revokedAt])->save();
            }

            McpConnection::query()
                ->where('team_id', $team->id)
                ->where('user_id', $user->id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => $revokedAt]);

            OAuthRefreshToken::query()
                ->where('team_id', $team->id)
                ->where('user_id', $user->id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => $revokedAt]);
        });

        return true;
    }

    /**
     * End every sessionless credential that was not proven by a Polis sign-in
     * for this team (RUB-482). Called when `auth_mode` becomes `polis` so an
     * AuthKit-minted MCP connection cannot keep minting access tokens.
     *
     * Org tokens have no Polis provenance bit: they are always sessionless, so
     * they are all revoked. OAuth refresh tokens and their MCP connections are
     * revoked only when `polis_proven` is false.
     */
    public function revokeNonPolisCredentialsForTeam(Team $team): bool
    {
        $tokens = OrgToken::query()
            ->where('team_id', $team->id)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->get();

        $authKitRefreshTokens = OAuthRefreshToken::query()
            ->where('team_id', $team->id)
            ->where('polis_proven', false)
            ->whereNull('revoked_at')
            ->get();

        $connectionIds = $authKitRefreshTokens
            ->pluck('mcp_connection_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $revokedAt = now();

        if (! $this->denylistTokens($tokens)) {
            return false;
        }

        DB::transaction(function () use ($team, $tokens, $authKitRefreshTokens, $connectionIds, $revokedAt): void {
            foreach ($tokens as $token) {
                $token->forceFill(['revoked_at' => $revokedAt])->save();
            }

            if ($authKitRefreshTokens->isNotEmpty()) {
                OAuthRefreshToken::query()
                    ->whereIn('id', $authKitRefreshTokens->modelKeys())
                    ->whereNull('revoked_at')
                    ->update(['revoked_at' => $revokedAt]);
            }

            if ($connectionIds !== []) {
                McpConnection::query()
                    ->where('team_id', $team->id)
                    ->whereIn('id', $connectionIds)
                    ->whereNull('revoked_at')
                    ->update(['revoked_at' => $revokedAt]);
            }

            // Connections with no refresh row still count as AuthKit-era
            // sessionless access when the team goes SSO-only.
            McpConnection::query()
                ->where('team_id', $team->id)
                ->whereNull('revoked_at')
                ->whereDoesntHave('oauthRefreshTokens', fn ($query) => $query->where('polis_proven', true)->whereNull('revoked_at'))
                ->update(['revoked_at' => $revokedAt]);
        });

        return true;
    }

    /** @param iterable<OrgToken> $tokens */
    public function denylistTokens(iterable $tokens): bool
    {
        $revocationFailed = false;

        foreach ($tokens as $token) {
            try {
                if (! RevocationWriter::default()->revoke($token->jti, $token->expires_at)) {
                    $revocationFailed = true;

                    Log::warning('Worker token revocation was not confirmed.', [
                        'team_id' => $token->team_id,
                        'token_id' => $token->id,
                    ]);
                }
            } catch (Throwable) {
                $revocationFailed = true;

                Log::warning('Worker token revocation request failed.', [
                    'team_id' => $token->team_id,
                    'token_id' => $token->id,
                ]);
            }
        }

        if ($revocationFailed) {
            return false;
        }

        return true;
    }
}
