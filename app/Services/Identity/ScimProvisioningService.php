<?php

namespace App\Services\Identity;

use App\Enums\TeamRole;
use App\Models\ExternalIdentity;
use App\Models\Team;
use App\Models\User;
use App\Services\Auth\RevocationWriter;
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
    private readonly RevocationWriter $revocationWriter;

    public function __construct(?RevocationWriter $revocationWriter = null)
    {
        $this->revocationWriter = $revocationWriter ?? RevocationWriter::default();
    }

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
    public function deprovision(string $scimExternalId): void
    {
        $identity = ExternalIdentity::query()
            ->where('provider', 'scim')
            ->where('external_id', $scimExternalId)
            ->first();

        if (! $identity) {
            return;
        }

        $user = $identity->user;
        $user->forceFill(['deactivated_at' => now()])->save();

        $user->orgTokens()
            ->whereNull('revoked_at')
            ->get()
            ->each(function ($token): void {
                $token->revoked_at = now();
                $token->save();
                $this->revocationWriter->revoke($token->jti, $token->expires_at);
            });
    }
}
