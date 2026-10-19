<?php

namespace App\Http\Controllers\Ops\Actions;

use App\Enums\PaymentStatus;
use App\Enums\Plan;
use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Services\Operator\Actions\ChangePlanAction;
use App\Services\Operator\Actions\OperatorActionRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Changes a tenant's plan and payment status: a comp or an extension
 * (spec 17). The page shows the diff first and posts `confirm`.
 */
class PlanController extends Controller
{
    public function __invoke(Request $request, Team $team, ChangePlanAction $action, OperatorActionRecorder $recorder): RedirectResponse
    {
        $validated = $request->validate([
            'plan' => ['required', Rule::enum(Plan::class)],
            'payment_status' => ['required', Rule::enum(PaymentStatus::class)],
            'confirm' => ['nullable', 'boolean'],
            'reason' => OperatorActionRecorder::REASON_RULES,
        ]);

        $status = $action->run(
            $request,
            $team,
            Plan::from($validated['plan']),
            PaymentStatus::from($validated['payment_status']),
            $request->boolean('confirm'),
            $recorder->reasonFrom($request),
        );

        return redirect()->route('ops.tenants.show', $team)->with('status', $status);
    }
}
