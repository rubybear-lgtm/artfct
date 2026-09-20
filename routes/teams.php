<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\ConsoleController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Teams\AuditLogController;
use App\Http\Controllers\Teams\AuthModeController;
use App\Http\Controllers\Teams\BillingController;
use App\Http\Controllers\Teams\GovernanceController;
use App\Http\Controllers\Teams\InvitationLandingController;
use App\Http\Controllers\Teams\OrgTokenController;
use App\Http\Controllers\Teams\SearchPageController;
use App\Http\Controllers\Teams\TeamController;
use App\Http\Controllers\Teams\TeamDomainController;
use App\Http\Controllers\Teams\TeamInvitationController;
use App\Http\Controllers\Teams\TeamMemberController;
use App\Http\Controllers\Teams\TeamOwnerController;
use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Support\Facades\Route;

Route::get('invitations/{invitation}', InvitationLandingController::class)->name('invitations.show');

Route::prefix('{current_team}')
    ->middleware(['auth', EnsureTeamMembership::class])
    ->group(function () {
        Route::get('dashboard', DashboardController::class)->name('dashboard');
    });

Route::middleware(['auth'])->group(function () {
    Route::get('settings/account', [AccountController::class, 'show'])->name('account.show');
    Route::patch('settings/account', [AccountController::class, 'update'])->name('account.update');
    Route::delete('settings/account', [AccountController::class, 'destroy'])->name('account.destroy');

    Route::post('invitations/{invitation}/accept', [TeamInvitationController::class, 'accept'])->name('invitations.accept');
    Route::delete('invitations/{invitation}', [TeamInvitationController::class, 'decline'])->name('invitations.decline');

    // Console routes (manually resolve team for 404 on cross-org access, not 403)
    Route::get('settings/teams/{team}/billing', [BillingController::class, 'show'])->name('teams.billing.show');
    Route::post('settings/teams/{team}/billing/cancel', [BillingController::class, 'cancel'])->name('teams.billing.cancel');
    Route::get('settings/teams/{team}/search', SearchPageController::class)->name('teams.search');
    Route::get('settings/teams/{team}/audit', [AuditLogController::class, 'index'])->name('teams.audit.index');
    Route::get('settings/teams/{team}/audit/export', [AuditLogController::class, 'export'])->name('teams.audit.export');
    Route::get('settings/teams/{team}/tokens', [OrgTokenController::class, 'index'])->name('teams.tokens.index');

    Route::get('settings/teams/{team}/console', [ConsoleController::class, 'index'])->name('console.index');
    Route::post('settings/teams/{team}/console/artifacts/{artifactId}/reindex', [ConsoleController::class, 'reindex'])->name('console.reindex');
    Route::patch('settings/teams/{team}/console/artifacts/{artifactId}/revoke', [ConsoleController::class, 'revoke'])->name('console.revoke');
    Route::get('settings/teams/{team}/console/export', [ConsoleController::class, 'export'])->name('console.export');

    Route::get('settings/teams', [TeamController::class, 'index'])->name('teams.index');
    Route::post('settings/teams', [TeamController::class, 'store'])->middleware('throttle:team-creation')->name('teams.store');

    Route::middleware(EnsureTeamMembership::class)->group(function () {
        Route::get('settings/teams/{team}', [TeamController::class, 'edit'])->name('teams.edit');
        Route::patch('settings/teams/{team}', [TeamController::class, 'update'])->name('teams.update');
        Route::delete('settings/teams/{team}', [TeamController::class, 'destroy'])->name('teams.destroy');
        Route::post('settings/teams/{team}/switch', [TeamController::class, 'switch'])->name('teams.switch');
        Route::patch('settings/teams/{team}/owner', TeamOwnerController::class)->name('teams.owner.update');
        Route::delete('settings/teams/{team}/leave', [TeamController::class, 'leave'])->name('teams.leave');

        Route::patch('settings/teams/{team}/members/{user}', [TeamMemberController::class, 'update'])->name('teams.members.update');
        Route::delete('settings/teams/{team}/members/{user}', [TeamMemberController::class, 'destroy'])->name('teams.members.destroy');

        Route::post('settings/teams/{team}/invitations', [TeamInvitationController::class, 'store'])->middleware('throttle:invitations')->name('teams.invitations.store');
        Route::post('settings/teams/{team}/invitations/{invitation}/resend', [TeamInvitationController::class, 'resend'])->middleware('throttle:invitations')->name('teams.invitations.resend');
        Route::delete('settings/teams/{team}/invitations/{invitation}', [TeamInvitationController::class, 'destroy'])->name('teams.invitations.destroy');

        Route::patch('settings/teams/{team}/auth-mode', [AuthModeController::class, 'update'])->name('teams.auth-mode.update');

        Route::patch('settings/teams/{team}/retention', [GovernanceController::class, 'updateRetention'])->name('teams.retention.update');

        Route::post('settings/teams/{team}/billing/checkout', [BillingController::class, 'checkout'])->name('teams.billing.checkout');
        Route::post('settings/teams/{team}/billing/resume', [BillingController::class, 'resume'])->name('teams.billing.resume');
        Route::post('settings/teams/{team}/billing/portal', [BillingController::class, 'portal'])->name('teams.billing.portal');
        Route::patch('settings/teams/{team}/billing/hostname', [BillingController::class, 'updateHostname'])->name('teams.billing.hostname');

        Route::post('settings/teams/{team}/domains', [TeamDomainController::class, 'store'])->name('teams.domains.store');
        Route::post('settings/teams/{team}/domains/{domain}/verify', [TeamDomainController::class, 'verify'])->name('teams.domains.verify');
        Route::delete('settings/teams/{team}/domains/{domain}', [TeamDomainController::class, 'destroy'])->name('teams.domains.destroy');

        Route::post('settings/teams/{team}/tokens', [OrgTokenController::class, 'store'])->name('teams.tokens.store');
        Route::delete('settings/teams/{team}/tokens/{token}', [OrgTokenController::class, 'destroy'])->name('teams.tokens.destroy');
    });
});
