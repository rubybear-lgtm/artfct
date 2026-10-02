<?php

namespace App\Http\Controllers;

use App\Actions\Teams\CreateTeam;
use App\Enums\AuditEventType;
use App\Services\Governance\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * First-run: a user nobody invited to a team is asked to create one. Anyone
 * who already has a team, or a pending invitation, goes straight to the app.
 */
class OnboardingController extends Controller
{
    public function show(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if (! $user->needsFirstTeam()) {
            return redirect()->route('teams.index');
        }

        return Inertia::render('onboarding/team', ['suggestedName' => $user->name."'s team"]);
    }

    public function store(Request $request, CreateTeam $createTeam, AuditLogger $auditLogger): RedirectResponse
    {
        $validated = $request->validate(['name' => ['required', 'string', 'min:2', 'max:100']]);

        $team = $createTeam->handle($request->user(), trim($validated['name']));

        $auditLogger->recordForRequest($request, AuditEventType::TeamCreated, $team, (string) $request->user()->id, $team->slug);

        return redirect()->route('dashboard', ['current_team' => $team->slug]);
    }
}
