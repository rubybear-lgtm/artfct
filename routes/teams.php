<?php

use App\Http\Controllers\ConsoleController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Teams\AuthModeController;
use App\Http\Controllers\Teams\OrgTokenController;
use App\Http\Controllers\Teams\TeamController;
use App\Http\Controllers\Teams\TeamDomainController;
use App\Http\Controllers\Teams\TeamInvitationController;
use App\Http\Controllers\Teams\TeamMemberController;
use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Support\Facades\Route;

Route::prefix('{current_team}')
    ->middleware(['auth', EnsureTeamMembership::class])
    ->group(function () {
        Route::get('dashboard', DashboardController::class)->name('dashboard');
    });

Route::middleware(['auth'])->group(function () {
    Route::post('invitations/{invitation}/accept', [TeamInvitationController::class, 'accept'])->name('invitations.accept');
    Route::delete('invitations/{invitation}', [TeamInvitationController::class, 'decline'])->name('invitations.decline');

    // Console routes (manually resolve team for 404 on cross-org access, not 403)
    Route::get('settings/teams/{team}/console', [ConsoleController::class, 'index'])->name('console.index');
    Route::patch('settings/teams/{team}/console/artifacts/{artifactId}/revoke', [ConsoleController::class, 'revoke'])->name('console.revoke');
    Route::get('settings/teams/{team}/console/export', [ConsoleController::class, 'export'])->name('console.export');

    Route::get('settings/teams', [TeamController::class, 'index'])->name('teams.index');
    Route::post('settings/teams', [TeamController::class, 'store'])->name('teams.store');

    Route::middleware(EnsureTeamMembership::class)->group(function () {
        Route::get('settings/teams/{team}', [TeamController::class, 'edit'])->name('teams.edit');
        Route::patch('settings/teams/{team}', [TeamController::class, 'update'])->name('teams.update');
        Route::delete('settings/teams/{team}', [TeamController::class, 'destroy'])->name('teams.destroy');
        Route::post('settings/teams/{team}/switch', [TeamController::class, 'switch'])->name('teams.switch');
        Route::delete('settings/teams/{team}/leave', [TeamController::class, 'leave'])->name('teams.leave');

        Route::patch('settings/teams/{team}/members/{user}', [TeamMemberController::class, 'update'])->name('teams.members.update');
        Route::delete('settings/teams/{team}/members/{user}', [TeamMemberController::class, 'destroy'])->name('teams.members.destroy');

        Route::post('settings/teams/{team}/invitations', [TeamInvitationController::class, 'store'])->name('teams.invitations.store');
        Route::delete('settings/teams/{team}/invitations/{invitation}', [TeamInvitationController::class, 'destroy'])->name('teams.invitations.destroy');

        Route::patch('settings/teams/{team}/auth-mode', [AuthModeController::class, 'update'])->name('teams.auth-mode.update');

        Route::post('settings/teams/{team}/domains', [TeamDomainController::class, 'store'])->name('teams.domains.store');
        Route::post('settings/teams/{team}/domains/{domain}/verify', [TeamDomainController::class, 'verify'])->name('teams.domains.verify');
        Route::delete('settings/teams/{team}/domains/{domain}', [TeamDomainController::class, 'destroy'])->name('teams.domains.destroy');

        Route::post('settings/teams/{team}/tokens', [OrgTokenController::class, 'store'])->name('teams.tokens.store');
        Route::delete('settings/teams/{team}/tokens/{token}', [OrgTokenController::class, 'destroy'])->name('teams.tokens.destroy');
    });
});
