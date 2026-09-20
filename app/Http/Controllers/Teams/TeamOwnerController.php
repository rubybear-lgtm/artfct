<?php

namespace App\Http\Controllers\Teams;

use App\Enums\AuditEventType;
use App\Enums\TeamRole;
use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Models\User;
use App\Services\Governance\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Hands team ownership to another admin. Only the current owner may do it;
 * the previous owner stays an admin and can then leave or be demoted.
 */
class TeamOwnerController extends Controller
{
    public function __invoke(Request $request, Team $team, AuditLogger $auditLogger): RedirectResponse
    {
        Gate::authorize('transferOwnership', $team);

        $validated = $request->validate(['user_id' => ['required', 'integer']]);

        $newOwner = User::query()->find($validated['user_id']);
        $isAdmin = $newOwner !== null
            && $team->memberships()->where('user_id', $newOwner->id)->where('role', TeamRole::Admin->value)->exists();

        if (! $isAdmin) {
            throw ValidationException::withMessages(['user_id' => __('Ownership can only go to an admin of this team.')]);
        }

        $previousOwnerId = $team->owner_user_id;
        $team->forceFill(['owner_user_id' => $newOwner->id])->save();

        $auditLogger->recordForRequest($request, AuditEventType::OwnershipTransferred, $team, (string) $request->user()->id, "user:{$previousOwnerId} -> user:{$newOwner->id}");

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Ownership transferred.')]);

        return to_route('teams.edit', ['team' => $team->slug]);
    }
}
