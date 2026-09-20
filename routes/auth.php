<?php

use App\Http\Controllers\Auth\AuthKitCallbackController;
use App\Http\Controllers\Auth\AuthKitDevLoginController;
use App\Http\Controllers\Auth\AuthKitLoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\PolisCallbackController;
use Illuminate\Support\Facades\Route;

Route::middleware(['guest', 'throttle:auth'])->group(function () {
    Route::get('login', AuthKitLoginController::class)->name('login');
    Route::get('authenticate', AuthKitCallbackController::class)->name('authenticate');
    Route::get('teams/{team}/sso/authenticate', PolisCallbackController::class)->name('sso.authenticate');

    // Dev/test-only stand-in for WorkOS's hosted login screen — never
    // available in production (see App\Providers\AppServiceProvider,
    // which only binds RealAuthKitClient there).
    if (! app()->environment('production')) {
        Route::post('authkit/dev-login', AuthKitDevLoginController::class)->name('authkit.dev-login');
    }
});

Route::post('logout', LogoutController::class)
    ->middleware(['auth'])->name('logout');
