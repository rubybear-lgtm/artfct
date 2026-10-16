<?php

namespace App\Services\Sharing;

use App\Models\Team;
use App\Models\User;

/**
 * The Private rule for server-side readers (RUB-438). The Worker enforces
 * Private on every credentialed read; a Laravel read that mints a system
 * token with `artifacts:read_private` sees every sharing level and must apply
 * the rule itself before showing or linking what it read.
 *
 * `private` is visible to the artifact's owner and to team admins, and to
 * nobody else. A `null` or unrecognised sharing level — an older Worker, a
 * truncated body — fails closed to private rather than open to the team.
 */
final class ArtifactVisibility
{
    public static function canView(?string $sharing, ?string $ownerUserId, User $user, Team $team): bool
    {
        if ($sharing !== null && in_array($sharing, ['team', 'public'], true)) {
            return true;
        }

        if ($ownerUserId !== null && (string) $user->id === $ownerUserId) {
            return true;
        }

        return $user->isAdminOf($team);
    }
}
