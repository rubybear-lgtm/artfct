<?php

namespace App\Http\Middleware;

use App\Models\Team;
use App\Models\User;
use App\Support\TeamSignIn;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies `auth_mode` to every team-scoped route in one place (RUB-458).
 *
 * It only acts for a signed-in member of the team in the route. A non-member
 * is left to the route's own answer (404 or 403), and a route with no team
 * parameter passes straight through. Registered on the authenticated route
 * group in routes/teams.php, so each team route passes through it.
 */
class EnsureTeamSignIn
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $team = $this->team($request);

        if ($user instanceof User && $team instanceof Team && $user->belongsToTeam($team)) {
            abort_unless(
                TeamSignIn::admits($request, $user, $team),
                403,
                "This organisation only accepts sign-in through its single sign-on. Sign in with your organisation's SSO to continue.",
            );
        }

        return $next($request);
    }

    private function team(Request $request): ?Team
    {
        $team = $request->route('current_team') ?? $request->route('team');

        if (is_string($team)) {
            $team = Team::query()->where('slug', $team)->first();
        }

        return $team instanceof Team ? $team : null;
    }
}
