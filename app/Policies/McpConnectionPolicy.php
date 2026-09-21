<?php

namespace App\Policies;

use App\Enums\TeamPermission;
use App\Models\McpConnection;
use App\Models\Team;
use App\Models\User;

class McpConnectionPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user, Team $team): bool
    {
        return $user->belongsToTeam($team);
    }

    /**
     * Determine whether the user can revoke the model.
     */
    public function revoke(User $user, McpConnection $mcpConnection): bool
    {
        if ($user->id === $mcpConnection->user_id) {
            return true;
        }

        return $user->hasTeamPermission($mcpConnection->team, TeamPermission::RemoveMember);
    }
}
