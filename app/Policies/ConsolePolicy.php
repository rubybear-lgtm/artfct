<?php

namespace App\Policies;

use App\Models\Team;
use App\Models\User;

/**
 * Console policy for viewing and managing artifacts.
 * The console itself is visible to all team members, but
 * revoke and export are admin-only.
 */
class ConsolePolicy
{
    /**
     * Determine if the user can view the console (list artifacts).
     */
    public function view(User $user, Team $team): bool
    {
        return $user->belongsToTeam($team);
    }

    /**
     * Determine if the user can revoke an artifact.
     */
    public function revoke(User $user, Team $team): bool
    {
        return $user->isAdminOf($team);
    }

    /**
     * Determine if the user can export artifacts.
     */
    public function export(User $user, Team $team): bool
    {
        return $user->isAdminOf($team);
    }
}
