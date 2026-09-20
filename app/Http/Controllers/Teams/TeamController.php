<?php

namespace App\Http\Controllers\Teams;

use App\Actions\Teams\CreateTeam;
use App\Enums\AuditEventType;
use App\Enums\Plan;
use App\Enums\TeamRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Teams\DeleteTeamRequest;
use App\Http\Requests\Teams\SaveTeamRequest;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use App\Services\Auth\RevocationWriter;
use App\Services\Governance\AuditLogger;
use App\Services\Teams\LastAdminGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class TeamController extends Controller
{
    /**
     * Display a listing of the user's teams.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('teams/index', [
            'teams' => $user->toUserTeams(includeCurrent: true),
        ]);
    }

    /**
     * Store a newly created team.
     */
    public function store(SaveTeamRequest $request, CreateTeam $createTeam, AuditLogger $auditLogger): RedirectResponse
    {
        $team = $createTeam->handle(
            $request->user(),
            $request->validated('name'),
            slug: $request->validated('slug'),
        );

        $auditLogger->recordForRequest($request, AuditEventType::TeamCreated, $team, (string) $request->user()->id, $team->slug);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Team created.')]);

        return to_route('teams.edit', ['team' => $team->slug]);
    }

    /**
     * Show the team edit page.
     */
    public function edit(Request $request, Team $team): Response
    {
        $user = $request->user();

        return Inertia::render('teams/edit', [
            'team' => [
                'id' => $team->id,
                'name' => $team->name,
                'slug' => $team->slug,
                'isPersonal' => $team->is_personal,
                'authMode' => $team->auth_mode->value,
                'plan' => ($team->plan ?? Plan::Free)->value,
                'ownerId' => $team->owner_user_id,
            ],
            'viewer' => [
                'id' => $user->id,
                'isOwner' => $team->owner_user_id !== null && $team->owner_user_id === $user->id,
                'canUpdateMember' => $user->can('updateMember', $team),
                'canRemoveMember' => $user->can('removeMember', $team),
                'canDelete' => $user->can('delete', $team),
                'canTransfer' => $user->can('transferOwnership', $team),
                'canLeave' => $user->can('leave', $team),
            ],
            'members' => $team->members()->get()->map(function (User $member) {
                /** @var Membership $membership */
                $membership = $member->getRelation('pivot');

                return [
                    'id' => $member->id,
                    'name' => $member->name,
                    'email' => $member->email,
                    'avatar' => $member->avatar ?? null,
                    'role' => $membership->role->value,
                    'role_label' => $membership->role->label(),
                    'deactivated' => $member->deactivated_at !== null,
                ];
            }),
            'invitations' => $team->invitations()
                ->whereNull('accepted_at')
                ->get()
                ->map(fn ($invitation) => [
                    'code' => $invitation->code,
                    'email' => $invitation->email,
                    'role' => $invitation->role->value,
                    'role_label' => $invitation->role->label(),
                    'created_at' => $invitation->created_at->toISOString(),
                    'url' => route('invitations.show', $invitation),
                ]),
            'domains' => $team->domains()->get()->map(fn ($domain) => [
                'id' => $domain->id,
                'domain' => $domain->domain,
                'verification_token' => $domain->verification_token,
                'txt_record_name' => $domain->txtRecordName(),
                'verified_at' => $domain->verified_at?->toISOString(),
            ]),
            'permissions' => $user->toTeamPermissions($team),
            'availableRoles' => TeamRole::assignable(),
        ]);
    }

    /**
     * Update the specified team.
     */
    public function update(SaveTeamRequest $request, Team $team, AuditLogger $auditLogger): RedirectResponse
    {
        Gate::authorize('update', $team);

        $previousName = $team->name;

        $team = DB::transaction(function () use ($request, $team) {
            $team = Team::whereKey($team->id)->lockForUpdate()->firstOrFail();

            $team->update(['name' => $request->validated('name')]);

            return $team;
        });

        if ($previousName !== $team->name) {
            $auditLogger->recordForRequest($request, AuditEventType::TeamRenamed, $team, (string) $request->user()->id, "{$previousName} -> {$team->name}");
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Team updated.')]);

        return to_route('teams.edit', ['team' => $team->slug]);
    }

    /**
     * Switch the user's current team.
     */
    public function switch(Request $request, Team $team): RedirectResponse
    {
        abort_unless($request->user()->belongsToTeam($team), 403);

        $request->user()->switchTeam($team);

        return back();
    }

    /**
     * Leave the specified team.
     */
    public function leave(Request $request, Team $team, AuditLogger $auditLogger): RedirectResponse
    {
        Gate::authorize('leave', $team);

        $user = $request->user();

        app(LastAdminGuard::class)->ensureNotOwner($team, $user);
        app(LastAdminGuard::class)->ensureAdminRemains($team, $user);

        $fallbackTeam = $user->isCurrentTeam($team)
            ? $user->fallbackTeam($team)
            : null;

        $team->memberships()
            ->where('user_id', $user->id)
            ->delete();

        $auditLogger->recordForRequest($request, AuditEventType::MemberLeft, $team, (string) $user->id, "user:{$user->id}");

        if ($fallbackTeam) {
            $user->switchTeam($fallbackTeam);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('You left the team ":name"', ['name' => $team->name])]);

        return to_route('teams.index');
    }

    /**
     * Delete the specified team.
     */
    public function destroy(DeleteTeamRequest $request, Team $team, AuditLogger $auditLogger): RedirectResponse
    {
        $user = $request->user();
        $fallbackTeam = $user->isCurrentTeam($team)
            ? $user->fallbackTeam($team)
            : null;

        // A deleted team's tokens must stop working at the edge, not just here.
        $team->orgTokens()->whereNull('revoked_at')->get()->each(function ($token): void {
            $token->revoked_at = now();
            $token->save();
            RevocationWriter::default()->revoke($token->jti, $token->expires_at);
        });

        $auditLogger->recordForRequest($request, AuditEventType::TeamDeleted, $team, (string) $user->id, $team->slug);

        DB::transaction(function () use ($user, $team) {
            User::where('current_team_id', $team->id)
                ->where('id', '!=', $user->id)
                ->each(fn (User $affectedUser) => $affectedUser->switchTeam($affectedUser->personalTeam()));

            $team->invitations()->delete();
            $team->memberships()->delete();
            $team->delete();
        });

        if ($fallbackTeam) {
            $user->switchTeam($fallbackTeam);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Team deleted.')]);

        return to_route('teams.index');
    }
}
