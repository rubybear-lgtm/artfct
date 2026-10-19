<?php

namespace App\Actions\Teams;

use App\Enums\AuditEventType;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Rules\ValidTeamInvitation;
use App\Services\Governance\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AcceptTeamInvitation
{
    public function __construct(private AuditLogger $auditLogger) {}

    /**
     * Add the user to the invitation's team, mark the invitation accepted and
     * make that team current. The caller has already checked the invitation
     * is valid for this user (see {@see ValidTeamInvitation}).
     */
    public function handle(Request $request, User $user, TeamInvitation $invitation): void
    {
        DB::transaction(function () use ($user, $invitation) {
            $team = $invitation->team;

            $team->memberships()->firstOrCreate(
                ['user_id' => $user->id],
                ['role' => $invitation->role],
            );

            $invitation->update(['accepted_at' => now()]);

            $user->switchTeam($team);
        });

        $this->auditLogger->recordForRequest($request, AuditEventType::MemberAdded, $invitation->team, (string) $user->id, "user:{$user->id}");
    }
}
