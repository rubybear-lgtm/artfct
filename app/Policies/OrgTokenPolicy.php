<?php

namespace App\Policies;

use App\Enums\TeamPermission;
use App\Models\OrgToken;
use App\Models\Team;
use App\Models\User;

class OrgTokenPolicy
{
    /**
     * Determine whether the user can create org tokens for the team.
     */
    public function create(User $user, Team $team): bool
    {
        return $user->belongsToTeam($team);
    }

    /**
     * Determine whether the user can revoke the given token: its own
     * creator always can; anyone else needs the team's member-management
     * permission (spec 07: "member cannot revoke another user's token").
     */
    public function revoke(User $user, OrgToken $token): bool
    {
        if ($user->id === $token->user_id) {
            return true;
        }

        return $user->hasTeamPermission($token->team, TeamPermission::RemoveMember);
    }
}
