<?php

namespace App\Http\Controllers\Teams;

use App\Http\Controllers\Controller;
use App\Http\Requests\Teams\AddTeamDomainRequest;
use App\Models\Team;
use App\Models\TeamDomain;
use App\Services\Identity\DomainVerifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class TeamDomainController extends Controller
{
    /**
     * Claim a domain for the team, pending TXT verification.
     */
    public function store(AddTeamDomainRequest $request, Team $team): RedirectResponse
    {
        Gate::authorize('manageDomains', $team);

        $team->domains()->create([
            'domain' => strtolower($request->validated('domain')),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Domain added. Add the TXT record to verify it.')]);

        return to_route('teams.edit', ['team' => $team->slug]);
    }

    /**
     * Attempt DNS TXT verification for the domain.
     */
    public function verify(Team $team, TeamDomain $domain, DomainVerifier $verifier): RedirectResponse
    {
        Gate::authorize('manageDomains', $team);

        abort_unless($domain->team_id === $team->id, 404);

        if (! $verifier->verify($domain)) {
            throw ValidationException::withMessages([
                'domain' => __('The TXT record for :name was not found. Add it and try again.', ['name' => $domain->txtRecordName()]),
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Domain verified.')]);

        return to_route('teams.edit', ['team' => $team->slug]);
    }

    /**
     * Remove a domain claim from the team.
     */
    public function destroy(Team $team, TeamDomain $domain): RedirectResponse
    {
        Gate::authorize('manageDomains', $team);

        abort_unless($domain->team_id === $team->id, 404);

        $domain->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Domain removed.')]);

        return to_route('teams.edit', ['team' => $team->slug]);
    }
}
