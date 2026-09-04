<?php

namespace App\Policies;

use App\Enums\TeamPermission;
use App\Models\Team;
use App\Models\User;

class TeamPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Team $team): bool
    {
        return $user->belongsToTeam($team);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, TeamPermission::UpdateTeam);
    }

    /**
     * Determine whether the user can leave the team.
     */
    public function leave(User $user, Team $team): bool
    {
        return ! $team->is_personal
            && $user->belongsToTeam($team)
            && ! $user->isAdminOf($team);
    }

    /**
     * Determine whether the user can add a member to the team.
     */
    public function addMember(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, TeamPermission::AddMember);
    }

    /**
     * Determine whether the user can update a member's role in the team.
     */
    public function updateMember(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, TeamPermission::UpdateMember);
    }

    /**
     * Determine whether the user can remove a member from the team.
     */
    public function removeMember(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, TeamPermission::RemoveMember);
    }

    /**
     * Determine whether the user can invite members to the team.
     */
    public function inviteMember(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, TeamPermission::CreateInvitation);
    }

    /**
     * Determine whether the user can cancel invitations.
     */
    public function cancelInvitation(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, TeamPermission::CancelInvitation);
    }

    /**
     * Determine whether the user can change the team's `auth_mode`.
     */
    public function changeAuthMode(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, TeamPermission::ChangeAuthMode);
    }

    /**
     * Determine whether the user can manage the team's domain verification.
     */
    public function manageDomains(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, TeamPermission::ChangeAuthMode);
    }

    /**
     * Determine whether the user can change the team's retention policy or
     * place/release a legal hold (spec 11).
     */
    public function manageGovernance(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, TeamPermission::ManageGovernance);
    }

    /**
     * Determine whether the user can start checkout, sync seats, or set
     * the team's custom hostname (spec 14).
     */
    public function manageBilling(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, TeamPermission::ManageBilling);
    }

    /**
     * Determine whether the user can pin a collection as canonical (spec
     * 16) — everything else about a collection (create, add/remove
     * artifacts) needs no permission at all; any member can.
     */
    public function pinCanonicalCollection(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, TeamPermission::PinCanonicalCollection);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Team $team): bool
    {
        return ! $team->is_personal && $user->hasTeamPermission($team, TeamPermission::DeleteTeam);
    }
}
