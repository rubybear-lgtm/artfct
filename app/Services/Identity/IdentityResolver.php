<?php

namespace App\Services\Identity;

use App\Models\ExternalIdentity;
use App\Models\User;
use App\Services\AuthKit\AuthKitProfile;
use Illuminate\Support\Facades\DB;

/**
 * Turns a normalized AuthKit profile into a `users` row, via
 * `external_identities` rather than a `workos_id` column (spec 06).
 *
 * Resolution order:
 *  1. Match an existing (provider, external_id) — the common case for a
 *     returning login.
 *  2. Else match `users.email`, but only when the incoming identity's
 *     email is verified — an unverified email would let anyone claim an
 *     existing account by registering with a matching (but unproven)
 *     address, the same account-takeover shape domain verification
 *     guards against.
 *  3. Else create a new user and identity.
 */
final class IdentityResolver
{
    /**
     * @return array{0: User, 1: bool} the resolved user, and whether it was newly created
     */
    public function resolve(AuthKitProfile $profile): array
    {
        return DB::transaction(function () use ($profile) {
            $identity = ExternalIdentity::query()
                ->where('provider', $profile->provider)
                ->where('external_id', $profile->externalId)
                ->first();

            if ($identity) {
                return [$identity->user, false];
            }

            $user = $profile->emailVerified
                ? User::query()->where('email', $profile->email)->first()
                : null;

            $wasCreated = false;

            if (! $user) {
                $user = User::query()->create([
                    'name' => $profile->fullName(),
                    'email' => $profile->email,
                    'email_verified_at' => $profile->emailVerified ? now() : null,
                    'avatar' => $profile->avatar,
                    'password' => null,
                ]);

                $wasCreated = true;
            }

            $user->externalIdentities()->create([
                'provider' => $profile->provider,
                'external_id' => $profile->externalId,
                'email' => $profile->email,
                'verified_at' => $profile->emailVerified ? now() : null,
            ]);

            return [$user, $wasCreated];
        });
    }
}
