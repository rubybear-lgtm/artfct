<?php

namespace App\Services\Teams;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * A team must always keep at least one admin. Leaving, being demoted and
 * being removed all reduce the admin count, so they share this one rule.
 */
final class LastAdminGuard
{
    /**
     * Pure rule: would this change leave the team with no admin?
     *
     * @param  int  $adminCount  admins in the team before the change
     * @param  bool  $isAdmin  whether the affected member is an admin now
     * @param  bool  $staysAdmin  whether they remain an admin afterwards
     */
    public static function leavesNoAdmin(int $adminCount, bool $isAdmin, bool $staysAdmin): bool
    {
        return $isAdmin && ! $staysAdmin && $adminCount <= 1;
    }

    /**
     * @throws ValidationException when the change would remove the last admin.
     */
    public function ensureAdminRemains(Team $team, User $user, ?TeamRole $newRole = null): void
    {
        $role = $team->memberships()->where('user_id', $user->id)->value('role');
        $role = $role instanceof TeamRole ? $role : TeamRole::tryFrom((string) $role);

        $blocked = self::leavesNoAdmin(
            $team->memberships()->where('role', TeamRole::Admin->value)->count(),
            $role === TeamRole::Admin,
            $newRole === TeamRole::Admin,
        );

        if ($blocked) {
            throw ValidationException::withMessages([
                'role' => __('A team must keep at least one admin. Promote another member first.'),
            ]);
        }
    }

    /**
     * The owner cannot leave, be removed or be demoted until ownership is
     * transferred.
     *
     * @throws ValidationException
     */
    public function ensureNotOwner(Team $team, User $user): void
    {
        if ($team->owner_user_id !== null && $team->owner_user_id === $user->id) {
            throw ValidationException::withMessages([
                'role' => __('Transfer ownership to another admin first.'),
            ]);
        }
    }
}
