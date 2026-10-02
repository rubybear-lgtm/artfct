<?php

namespace App\Services\Identity;

use App\Enums\TeamRole;
use App\Models\ExternalIdentity;
use App\Models\OAuthRefreshToken;
use App\Models\Team;
use App\Models\User;
use App\Services\Auth\OrgTokenRevoker;
use Illuminate\Support\Facades\DB;

/**
 * SCIM directory sync (spec 10): the IdP pushes user create/deactivate
 * events directly, ahead of and independent of any interactive login.
 * Provisioning creates a user (and a `scim` external identity, keyed by
 * the IdP's own user id, distinct from the `polis` identity that same
 * person gets on their first interactive SAML login) if one doesn't
 * already exist for that SCIM id. De-provisioning deactivates the user
 * (`deactivated_at`, blocking future logins without deleting the row or
 * their `external_identities`) and revokes every one of their live org
 * tokens via the same denylist write spec 07's OrgTokenController uses.
 */
final class ScimProvisioningService
{
    public function __construct(private readonly OrgTokenRevoker $tokenRevoker) {}

    public function provision(Team $team, string $scimExternalId, string $email, ?string $name = null): User
    {
        return DB::transaction(function () use ($team, $scimExternalId, $email, $name) {
            $identity = ExternalIdentity::query()
                ->where('provider', 'scim')
                ->where('external_id', $scimExternalId)
                ->first();

            if ($identity) {
                return $identity->user;
            }

            $user = User::query()->where('email', $email)->first();

            if (! $user) {
                $user = User::query()->create([
                    'name' => $name ?: $email,
                    'email' => $email,
                    'email_verified_at' => now(),
                    'password' => null,
                ]);
            }

            $user->externalIdentities()->create([
                'provider' => 'scim',
                'external_id' => $scimExternalId,
                'email' => $email,
                'verified_at' => now(),
            ]);

            if (! $team->members()->where('users.id', $user->id)->exists()) {
                $team->memberships()->create([
                    'user_id' => $user->id,
                    'role' => TeamRole::Member,
                ]);
            }

            return $user;
        });
    }

    /**
     * Deactivates the user identified by `$scimExternalId` and revokes
     * every one of their unrevoked org tokens.
     */
    public function deprovision(string $scimExternalId): bool
    {
        $identity = ExternalIdentity::query()
            ->where('provider', 'scim')
            ->where('external_id', $scimExternalId)
            ->first();

        if (! $identity) {
            return true;
        }

        $user = $identity->user;
        $tokens = $user->orgTokens()->where('expires_at', '>', now())->get();
        if (! $this->tokenRevoker->denylistTokens($tokens)) {
            return false;
        }

        DB::transaction(function () use ($user): void {
            $user->forceFill(['deactivated_at' => now()])->save();
            $user->orgTokens()->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $user->mcpConnections()->whereNull('revoked_at')->update(['revoked_at' => now()]);
            OAuthRefreshToken::query()->where('user_id', $user->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        });

        return true;
    }
}
