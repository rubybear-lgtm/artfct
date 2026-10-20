<?php

namespace App\Support;

use App\Enums\AuthMode;
use App\Http\Controllers\Auth\PolisCallbackController;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * The one definition of "may this signed-in user use this team right now"
 * for `auth_mode` (spec 06, RUB-458).
 *
 * `dual` and `authkit` teams admit any session. A `polis` team admits only a
 * session that a Polis sign-in for that very team and user wrote, which only
 * {@see PolisCallbackController} does. A session
 * from AuthKit cannot satisfy it, so a member cannot skip the org's IdP by
 * signing in through AuthKit. Personal teams have no restriction.
 *
 * Every session-backed team entry point calls this, and nothing else decides it.
 */
final class TeamSignIn
{
    /** Session key holding `[team_id => user_id]` for each team a Polis sign-in has proven. */
    public const SESSION_KEY = 'polis_sign_in';

    public static function admits(Request $request, User $user, Team $team): bool
    {
        if ($team->is_personal || $team->auth_mode !== AuthMode::Polis) {
            return true;
        }

        return self::provesPolisSignIn($request, $user, $team);
    }

    /**
     * Whether this session completed a Polis sign-in for the team as this user.
     */
    public static function provesPolisSignIn(Request $request, User $user, Team $team): bool
    {
        if (! $request->hasSession()) {
            return false;
        }

        $proven = $request->session()->get(self::SESSION_KEY);

        return is_array($proven) && ($proven[$team->id] ?? null) === $user->id;
    }

    /**
     * Record that this session just completed a Polis sign-in for the team.
     * Called after the session is regenerated, so the marker belongs to it.
     */
    public static function recordPolisSignIn(Request $request, User $user, Team $team): void
    {
        $proven = $request->session()->get(self::SESSION_KEY);
        $proven = is_array($proven) ? $proven : [];
        $proven[$team->id] = $user->id;

        $request->session()->put(self::SESSION_KEY, $proven);
    }

    /**
     * Drop every Polis sign-in marker, so an AuthKit sign-in can never inherit one.
     */
    public static function forgetPolisSignIns(Request $request): void
    {
        $request->session()->forget(self::SESSION_KEY);
    }
}
