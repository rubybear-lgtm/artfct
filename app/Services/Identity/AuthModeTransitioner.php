<?php

namespace App\Services\Identity;

use App\Enums\AuthMode;
use App\Enums\TeamRole;
use App\Models\ExternalIdentity;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Enforces `auth_mode`'s transition preconditions (spec 06, extended by
 * spec 10).
 *
 * Moving past `authkit` (to `dual` or `polis`) requires a verified domain —
 * without it, "enable SSO for @acme.com" is an account-takeover vector.
 * Moving to `polis` additionally requires at least one admin holding an
 * active Polis identity, which is what stops an admin flipping the switch
 * and locking themselves out of the screen where SAML is configured
 * (spec 06's "lockout" precondition) — and, if any member lacks a Polis
 * identity, requires explicit confirmation naming who will lose access
 * (spec 10's "absent from IdP" failure mode: contractors and personal
 * accounts must be surfaced, not silently locked out). Moving backward
 * (toward `authkit`) is always allowed.
 */
final class AuthModeTransitioner
{
    /** The `external_identities.provider` value for Polis (SAML) logins. */
    public const POLIS_PROVIDER = 'polis';

    /**
     * @throws AuthModeTransitionException when the transition is refused.
     */
    public function transition(Team $team, AuthMode $target, bool $confirmed = false): void
    {
        if ($target === $team->auth_mode) {
            return;
        }

        if ($this->movesForward($team->auth_mode, $target)) {
            if (! $team->hasVerifiedDomain()) {
                throw new AuthModeTransitionException(
                    "Cannot move auth_mode to [{$target->value}]: the org has no verified domain."
                );
            }

            if ($target === AuthMode::Polis) {
                if (! $this->hasAdminWithPolisIdentity($team)) {
                    throw new AuthModeTransitionException(
                        'Cannot move auth_mode to [polis]: no admin holds an active Polis identity — this would lock every admin out of SSO configuration.'
                    );
                }

                $atRisk = $this->membersWithoutPolisIdentity($team);
                if ($atRisk->isNotEmpty() && ! $confirmed) {
                    $names = $atRisk->pluck('email')->implode(', ');
                    throw new AuthModeTransitionException(
                        "Cannot move auth_mode to [polis] without confirmation: {$atRisk->count()} member(s) without a Polis identity will lose access ({$names}). Pass confirmed: true to proceed."
                    );
                }
            }
        }

        $team->forceFill(['auth_mode' => $target])->save();
    }

    /**
     * Every member of this team who does not hold a verified Polis
     * identity — spec 10's "absent from IdP" list, shown before
     * enforcement so contractors and personal accounts aren't silently
     * locked out.
     *
     * @return Collection<int, User>
     */
    public function membersWithoutPolisIdentity(Team $team): Collection
    {
        $linkedUserIds = ExternalIdentity::query()
            ->where('provider', self::POLIS_PROVIDER)
            ->whereNotNull('verified_at')
            ->pluck('user_id');

        return $team->members()->whereNotIn('users.id', $linkedUserIds)->get();
    }

    private function movesForward(AuthMode $current, AuthMode $target): bool
    {
        return $this->rank($target) > $this->rank($current);
    }

    private function rank(AuthMode $mode): int
    {
        return match ($mode) {
            AuthMode::AuthKit => 0,
            AuthMode::Dual => 1,
            AuthMode::Polis => 2,
        };
    }

    private function hasAdminWithPolisIdentity(Team $team): bool
    {
        $adminIds = $team->memberships()
            ->where('role', TeamRole::Admin->value)
            ->pluck('user_id');

        if ($adminIds->isEmpty()) {
            return false;
        }

        return ExternalIdentity::query()
            ->whereIn('user_id', $adminIds)
            ->where('provider', self::POLIS_PROVIDER)
            ->whereNotNull('verified_at')
            ->exists();
    }
}
