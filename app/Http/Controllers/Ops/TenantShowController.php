<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Services\Operator\OperatorAuditLogger;
use App\Services\Operator\TenantDetail;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One tenant's page in the operator console (spec 17). Reading it is itself
 * audited: the view is recorded once per staff user and tenant per hour.
 *
 * Every request logs, partial or not: the logger dedupes per staff user and
 * tenant, so the page's own deferred follow-up does not add a second event,
 * and a hand-made partial request cannot read tenant data unlogged.
 *
 * The usage and SSO panels are deferred props so the page renders without the
 * Worker or Polis, and a failure of either degrades its own panel alone.
 */
class TenantShowController extends Controller
{
    public function __invoke(
        Request $request,
        Team $team,
        TenantDetail $detail,
        OperatorAuditLogger $audit,
    ): Response {
        $audit->recordTenantViewForRequest($request, $team);

        return Inertia::render('ops/tenants/show', [
            'tenant' => fn (): array => $detail->tenant($team),
            'members' => fn (): array => $detail->members($team),
            'connections' => fn (): array => $detail->connections($team),
            'sso' => Inertia::defer(fn (): array => $detail->sso($team)),
            'usage' => Inertia::defer(fn (): array => $detail->usage($team)),
            'events' => fn (): array => $detail->events($team),
        ]);
    }
}
