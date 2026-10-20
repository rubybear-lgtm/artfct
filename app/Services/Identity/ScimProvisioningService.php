<?php

namespace App\Services\Identity;

use App\Enums\TeamRole;
use App\Models\ExternalIdentity;
use App\Models\Membership;
use App\Models\OAuthRefreshToken;
use App\Models\Team;
use App\Models\User;
use App\Services\Auth\OrgTokenRevoker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SCIM directory sync (spec 10): the IdP pushes user create/deactivate
 * events directly, ahead of and independent of any interactive login.
 * Provisioning creates a user (and a `scim:{team id}` external identity,
 * keyed by the IdP's own user id, distinct from the `polis` identity that
 * same person gets on their first interactive SAML login) if one doesn't
 * already exist for that SCIM id in that team. SCIM ids and the identities
 * they create are scoped to one team: a team's directory can only link,
 * add or remove its own members (RUB-456).
 *
 * De-provisioning removes the user from that team and revokes the org
 * tokens, MCP connections and refresh tokens they hold there. Only when no
 * other non-personal team membership remains is the user deactivated
 * (`deactivated_at`, blocking logins without deleting the row or their
 * `external_identities`), their browser sessions ended and staff access
 * cleared.
 */
final class ScimProvisioningService
{
    public function __construct(private readonly OrgTokenRevoker $tokenRevoker) {}

    public static function providerFor(Team $team): string
    {
        return 'scim:'.$team->id;
    }

    /**
     * Returns null when the event is refused: the email belongs to an existing
     * account that is not a member of `$team` and is outside the team's
     * verified domains, so the directory cannot claim it.
     */
    public function provision(Team $team, string $scimExternalId, string $email, ?string $name = null): ?User
    {
        return DB::transaction(function () use ($team, $scimExternalId, $email, $name) {
            $identity = $this->findIdentity($team, $scimExternalId);

            if ($identity) {
                $this->ensureMembership($team, $identity->user);

                return $identity->user;
            }

            $user = User::query()->where('email', $email)->first();

            if ($user && ! $this->mayLink($team, $user, $email)) {
                Log::warning('SCIM event refused: the email belongs to an account outside the team and its verified domains.', ['team_id' => $team->id]);

                return null;
            }

            if (! $user) {
                $user = User::query()->create([
                    'name' => $name ?: $email,
                    'email' => $email,
                    'email_verified_at' => now(),
                    'password' => null,
                ]);
            }

            $user->externalIdentities()->create([
                'provider' => self::providerFor($team),
                'external_id' => $scimExternalId,
                'email' => $email,
                'verified_at' => now(),
            ]);

            $this->ensureMembership($team, $user);

            return $user;
        });
    }

    /**
     * Removes the user identified by `$scimExternalId` from `$team` and revokes
     * their credentials there. Returns false when the Worker revocation could
     * not be confirmed, so the caller can have the IdP retry.
     */
    public function deprovision(Team $team, string $scimExternalId): bool
    {
        $identity = $this->findIdentity($team, $scimExternalId);

        if (! $identity) {
            return true;
        }

        $user = $identity->user;

        if (! $this->tokenRevoker->revokeForMember($team, $user)) {
            return false;
        }

        DB::transaction(function () use ($team, $user): void {
            Membership::query()->where('team_id', $team->id)->where('user_id', $user->id)->delete();

            if ($user->current_team_id === $team->id) {
                $user->forceFill(['current_team_id' => null])->save();
            }

            if ($this->hasOtherMembership($user)) {
                return;
            }

            $user->orgTokens()->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $user->mcpConnections()->whereNull('revoked_at')->update(['revoked_at' => now()]);
            OAuthRefreshToken::query()->where('user_id', $user->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $this->deactivate($user);
        });

        return true;
    }

    /**
     * Deactivates the user and ends everything that outlives a sign-in: browser
     * sessions and staff privileges. Also used when an account is closed.
     */
    public function deactivate(User $user): void
    {
        $user->forceFill([
            'deactivated_at' => now(),
            'staff_at' => null,
            'case_manager_at' => null,
        ])->save();

        DB::table('sessions')->where('user_id', $user->id)->delete();
    }

    /**
     * Finds the team's SCIM identity. A row written before identities were
     * scoped (`provider = scim`) is accepted only when its user is a member of
     * this team, and is rewritten to the scoped provider on the way.
     */
    private function findIdentity(Team $team, string $scimExternalId): ?ExternalIdentity
    {
        $identity = ExternalIdentity::query()
            ->where('provider', self::providerFor($team))
            ->where('external_id', $scimExternalId)
            ->first();

        if ($identity) {
            return $identity;
        }

        $legacy = ExternalIdentity::query()
            ->where('provider', 'scim')
            ->where('external_id', $scimExternalId)
            ->first();

        if (! $legacy || ! $team->members()->where('users.id', $legacy->user_id)->exists()) {
            return null;
        }

        $legacy->forceFill(['provider' => self::providerFor($team)])->save();

        return $legacy;
    }

    private function ensureMembership(Team $team, User $user): void
    {
        if (! $team->members()->where('users.id', $user->id)->exists()) {
            $team->memberships()->create([
                'user_id' => $user->id,
                'role' => TeamRole::Member,
            ]);
        }
    }

    private function mayLink(Team $team, User $user, string $email): bool
    {
        if ($team->members()->where('users.id', $user->id)->exists()) {
            return true;
        }

        $domain = strtolower(substr(strrchr($email, '@') ?: '', 1));

        return $domain !== ''
            && $team->domains()->whereNotNull('verified_at')->whereRaw('lower(domain) = ?', [$domain])->exists();
    }

    private function hasOtherMembership(User $user): bool
    {
        return Membership::query()
            ->where('user_id', $user->id)
            ->whereHas('team', fn ($query) => $query->where('is_personal', false))
            ->exists();
    }
}
