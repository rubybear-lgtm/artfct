<?php

use App\Http\Controllers\Ops\Actions\EraseController;
use App\Http\Controllers\Ops\Actions\ErasePreviewController;
use App\Http\Controllers\Ops\Actions\LimitsController;
use App\Http\Controllers\Ops\Actions\PlanController;
use App\Http\Controllers\Ops\Actions\ReindexController;
use App\Http\Controllers\Ops\HealthController;
use App\Http\Controllers\Ops\TenantIndexController;
use App\Http\Controllers\Ops\TenantShowController;
use App\Http\Middleware\EnsureStaff;
use App\Http\Middleware\EnsureTermsAccepted;
use App\Support\StaffAccess;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
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
        Route::get('/', TenantIndexController::class)->name('tenants.index');
        // Soft-deleted tenants stay reachable: the binding is the slug and
        // includes trashed teams, so an erased or deleted tenant is readable.
        Route::get('tenants/{team}', TenantShowController::class)->withTrashed()->name('tenants.show');
        Route::get('health', HealthController::class)->name('health');

        // Phase 2: audited support actions. Each needs a reason; erase also
        // needs a dry run and the typed slug.
        Route::post('tenants/{team}/reindex', ReindexController::class)->withTrashed()->name('tenants.reindex');
        Route::post('tenants/{team}/limits', LimitsController::class)->withTrashed()->name('tenants.limits');
        Route::post('tenants/{team}/plan', PlanController::class)->withTrashed()->name('tenants.plan');
        Route::post('tenants/{team}/erase/preview', ErasePreviewController::class)->withTrashed()->name('tenants.erase.preview');
        Route::post('tenants/{team}/erase', EraseController::class)->withTrashed()->name('tenants.erase');

        // Later units: add every real `/ops` route ABOVE this one.
        //
        // Without it, a POST to a GET-only `/ops` URL answers 405 with an
        // `Allow` header, which a refused visitor could use to tell the surface
        // exists. This answers the ordinary 404 for every non-GET method on a
        // URL no real route above has claimed. It carries no CSRF check, so a
        // forgery-token failure (419) cannot tell it apart from an unknown URL.
        Route::match(['post', 'put', 'patch', 'delete', 'options'], '{any?}', fn (Request $request) => throw StaffAccess::notFound($request))
            ->where('any', '.*')
            ->withoutMiddleware([PreventRequestForgery::class])
            ->name('fallback');
    });
