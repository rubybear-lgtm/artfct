<?php

use App\Http\Middleware\EnsureStaff;
use App\Http\Middleware\EnsureTermsAccepted;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Operator console (spec 17)
|--------------------------------------------------------------------------
|
| Outside `{current_team}`: the team membership and team URL-default
| middleware do not apply. `EnsureStaff` runs in place of `auth` so a signed-out
| visitor gets a 404, not a sign-in redirect. The terms gate is dropped for the
| same reason: it would redirect a non-staff user and reveal that the route
| exists.
|
*/

Route::prefix('ops')
    ->name('ops.')
    ->middleware([EnsureStaff::class, 'throttle:ops'])
    ->withoutMiddleware([EnsureTermsAccepted::class])
    ->group(function () {
        Route::get('/', fn () => response('ok'))->name('tenants.index');

        // Later units: add every real `/ops` route ABOVE this one.
        //
        // Without it, a POST to a GET-only `/ops` URL answers 405 with an
        // `Allow` header, which a refused visitor could use to tell the surface
        // exists. This answers the ordinary 404 for every non-GET method on a
        // URL no real route above has claimed. It carries no CSRF check, so a
        // forgery-token failure (419) cannot tell it apart from an unknown URL.
        Route::match(['post', 'put', 'patch', 'delete', 'options'], '{any?}', fn () => abort(404))
            ->where('any', '.*')
            ->withoutMiddleware([PreventRequestForgery::class])
            ->name('fallback');
    });
