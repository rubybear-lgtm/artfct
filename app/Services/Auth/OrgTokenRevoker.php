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
            ->active()
            ->get();
        $revokedAt = now();

        $revocationFailed = false;

        foreach ($tokens as $token) {
            try {
                if (! RevocationWriter::default()->revoke($token->jti, $token->expires_at)) {
                    $revocationFailed = true;

                    Log::warning('Worker token revocation was not confirmed.', [
                        'team_id' => $team->id,
                        'token_id' => $token->id,
                    ]);
                }
            } catch (Throwable) {
                $revocationFailed = true;

                Log::warning('Worker token revocation request failed.', [
                    'team_id' => $team->id,
                    'token_id' => $token->id,
                ]);
            }
        }

        if ($revocationFailed) {
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
}
