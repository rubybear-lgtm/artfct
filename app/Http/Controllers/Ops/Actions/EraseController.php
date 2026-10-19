<?php

namespace App\Http\Controllers\Ops\Actions;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Services\Operator\Actions\EraseTenantAction;
use App\Services\Operator\Actions\OperatorActionRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Erases a tenant's artifacts (spec 17). Irreversible: it needs a fresh dry
 * run for this slug, the slug typed exactly, and a reason.
 */
class EraseController extends Controller
{
    public function __invoke(Request $request, Team $team, EraseTenantAction $action, OperatorActionRecorder $recorder): RedirectResponse
    {
        $request->validate([
            'confirm_slug' => ['nullable', 'string', 'max:255'],
            'reason' => OperatorActionRecorder::REASON_RULES,
        ]);

        $status = $action->erase(
            $request,
            $team,
            (string) $request->input('confirm_slug'),
            $recorder->reasonFrom($request),
        );

        return redirect()->route('ops.tenants.index')->with('status', $status);
    }
}
