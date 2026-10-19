<?php

namespace App\Http\Controllers\Ops\Actions;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Services\Operator\Actions\OperatorActionRecorder;
use App\Services\Operator\Actions\PushLimitsAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Re-pushes a tenant's limits to the Worker (spec 17).
 */
class LimitsController extends Controller
{
    public function __invoke(Request $request, Team $team, PushLimitsAction $action, OperatorActionRecorder $recorder): RedirectResponse
    {
        $request->validate(['reason' => OperatorActionRecorder::REASON_RULES]);

        $status = $action->run($request, $team, $recorder->reasonFrom($request));

        return redirect()->route('ops.tenants.show', $team)->with('status', $status);
    }
}
