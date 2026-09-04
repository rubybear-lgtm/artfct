<?php

namespace App\Http\Controllers\Teams;

use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Spec 11: an org's retention policy. Legal hold and destructive
 * runs (retention/erasure) are operated via `governance:*` Artisan
 * commands, not this controller — the console surfaces the policy an
 * admin can set; running the job against production data is deliberately
 * an operator action, not a button in the UI.
 */
class GovernanceController extends Controller
{
    public function updateRetention(Request $request, Team $team): RedirectResponse
    {
        Gate::authorize('manageGovernance', $team);

        $validated = $request->validate([
            'retention_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ]);

        $team->update(['retention_days' => $validated['retention_days'] ?? null]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Retention policy updated.')]);

        return to_route('teams.edit', ['team' => $team->slug]);
    }
}
