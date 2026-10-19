<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Sign-in entry point. Everyone starts in their personal workspace; a team is
 * created later, when they upgrade, so there is nothing to ask on first run.
 */
class OnboardingController extends Controller
{
    public function show(Request $request): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        return $team
            ? redirect()->route('dashboard', ['current_team' => $team->slug])
            : redirect()->route('teams.index');
    }
}
