<?php

use App\Http\Middleware\EnsureStaff;
use App\Http\Middleware\EnsureTeamSignIn;
use Illuminate\Support\Facades\Route;

/*
 * RUB-458: the auth_mode sign-in check is applied once, in a route group, not
 * controller by controller. This walks every registered route with a team
 * parameter and fails if one of them does not pass through the check.
 */
test('every team scoped route passes through the auth_mode sign in check', function () {
    $checked = 0;
    $unchecked = [];

    foreach (Route::getRoutes() as $route) {
        if (array_intersect($route->parameterNames(), ['team', 'current_team']) === []) {
            continue;
        }

        // The staff console is authorised by EnsureStaff, and the SSO entry points are
        // guest-only, so neither has a signed-in team member to check.
        if (in_array('guest', $route->middleware(), true) || in_array(EnsureStaff::class, $route->gatherMiddleware(), true)) {
            continue;
        }

        $checked++;

        if (! in_array(EnsureTeamSignIn::class, $route->gatherMiddleware(), true)) {
            $unchecked[] = $route->methods()[0].' '.$route->uri();
        }
    }

    expect($checked)->toBeGreaterThan(40)
        ->and($unchecked)->toBe([]);
});
