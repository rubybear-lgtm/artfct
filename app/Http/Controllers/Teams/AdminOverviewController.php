<?php

namespace App\Http\Controllers\Teams;

use App\Enums\Plan;
use App\Enums\TeamRole;
use App\Http\Controllers\Controller;
use App\Models\McpConnection;
use App\Models\OrgToken;
use App\Models\Team;
use App\Services\Billing\PlanGate;
use App\Services\Billing\QuotaService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The admin's home for a team: the one place that gathers members, plan,
 * connected tools and the admin-only settings. Teammates (members and
 * viewers) get a 403; anyone outside the team gets the same 404 as a
 * missing team.
 */
class AdminOverviewController extends Controller
{
    public function __invoke(Request $request, Team $team, QuotaService $quota): Response
    {
        abort_unless($request->user()->belongsToTeam($team), 404);
        abort_unless($request->user()->isAdminOf($team), 403);

        try {
            $status = $quota->status($team);
            $usage = [
                'storageBytes' => $status->storageBytes,
                'storageLimitBytes' => $status->storageLimitBytes,
                'artifactsThisPeriod' => $status->artifactsThisPeriod,
                'artifactsLimit' => $status->artifactsLimit,
                'storagePercent' => round($status->storagePercent * 100, 1),
                'artifactsPercent' => round($status->artifactsPercent * 100, 1),
                'storageWarning' => $status->storageWarning,
                'artifactsWarning' => $status->artifactsWarning,
                'storageExceeded' => $status->storageExceeded,
                'artifactsExceeded' => $status->artifactsExceeded,
            ];
        } catch (\Throwable) {
            $usage = null;
        }

        return Inertia::render('teams/admin', [
            'team' => [
                'slug' => $team->slug,
                'name' => $team->name,
                'plan' => ($team->plan ?? Plan::Free)->value,
                'isEnterprise' => PlanGate::isEnterprise($team),
            ],
            'members' => [
                'total' => $team->memberships()->count(),
                'admins' => $team->memberships()->where('role', TeamRole::Admin->value)->count(),
            ],
            'pendingInvitations' => $team->invitations()->whereNull('accepted_at')->count(),
            'connections' => [
                'active' => McpConnection::query()->where('team_id', $team->id)->active()->count(),
            ],
            'tokens' => OrgToken::query()->where('team_id', $team->id)->count(),
            'usage' => $usage,
        ]);
    }
}
