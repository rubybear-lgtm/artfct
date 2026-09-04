<?php

namespace App\Services\Identity;

use App\Models\ExternalIdentity;
use App\Models\Team;
use App\Services\AuthKit\AuthKitProfile;
use Illuminate\Support\Facades\DB;

/**
 * Resolves a Polis (SAML/OIDC) login for one org (spec 10). Unlike
 * {@see IdentityResolver} — which creates a new user on an unmatched
 * AuthKit login, the intended first-registration path — this resolver
 * NEVER creates a user. An enterprise SSO login is always for someone who
 * already has, or should already have, a place in this org; silently
 * creating a second user for them is exactly the account-fragmentation
 * spec 10's "email mismatch" failure mode exists to prevent.
 *
 * Resolution order, scoped to this org's members only:
 *  1. Match an existing (provider, external_id) — the returning-login case.
 *  2. Else match `users.email` among this org's members, but only when
 *     the incoming identity's email is verified.
 *  3. Else: unlinked. No user is created; the caller surfaces this for an
 *     admin to link manually (spec 10's `dual`-mode "who has and hasn't
 *     linked" screen).
 */
final class EnterpriseIdentityResolver
{
    public function resolveOrgLogin(Team $team, AuthKitProfile $profile): EnterpriseLoginResult
    {
        return DB::transaction(function () use ($team, $profile) {
            $identity = ExternalIdentity::query()
                ->where('provider', $profile->provider)
                ->where('external_id', $profile->externalId)
                ->whereIn('user_id', $team->memberships()->pluck('user_id'))
                ->first();

            if ($identity) {
                return EnterpriseLoginResult::matched($identity->user);
            }

            $member = $profile->emailVerified
                ? $team->members()->where('email', $profile->email)->first()
                : null;

            if (! $member) {
                return EnterpriseLoginResult::unlinked();
            }

            $member->externalIdentities()->create([
                'provider' => $profile->provider,
                'external_id' => $profile->externalId,
                'email' => $profile->email,
                'verified_at' => $profile->emailVerified ? now() : null,
            ]);

            return EnterpriseLoginResult::matched($member);
        });
    }
}
