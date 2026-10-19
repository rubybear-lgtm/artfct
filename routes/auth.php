<?php

use App\Http\Controllers\Auth\AuthKitCallbackController;
use App\Http\Controllers\Auth\AuthKitDevLoginController;
use App\Http\Controllers\Auth\AuthKitLoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\PolisCallbackController;
use App\Http\Controllers\Auth\PolisLoginController;
use Illuminate\Support\Facades\Route;

Route::middleware(['guest', 'throttle:auth'])->group(function () {
    Route::get('login', AuthKitLoginController::class)->name('login');
    Route::get('authenticate', AuthKitCallbackController::class)->name('authenticate');
    Route::get('teams/{team}/sso/login', PolisLoginController::class)->name('sso.login');
    Route::get('teams/{team}/sso/authenticate', PolisCallbackController::class)->name('sso.authenticate');

    // Dev/test-only stand-in for WorkOS's hosted login screen. The route is
    // registered in every environment, because the login page imports its
    // generated Wayfinder action and a production build fails if the route is
    // missing when the actions are generated (it broke the first production
    // deploy). The controller answers 404 in production and unless dev login is
    // switched on, so it is never usable there.
    Route::post('authkit/dev-login', AuthKitDevLoginController::class)->name('authkit.dev-login');
});

Route::post('logout', LogoutController::class)
    ->middleware(['auth'])->name('logout');
