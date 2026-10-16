<?php

namespace App\Http\Controllers\Teams;

use App\Enums\AuditEventType;
use App\Enums\AuthMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Teams\UpdateAuthModeRequest;
use App\Models\Team;
use App\Services\Billing\PlanGate;
use App\Services\Billing\PlanGateException;
use App\Services\Governance\AuditLogger;
use App\Services\Identity\AuthModeTransitioner;
use App\Services\Identity\AuthModeTransitionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class AuthModeController extends Controller
{
    /**
     * Move the team's `auth_mode` to the requested state. Moving beyond
     * `authkit` (to `dual` or `polis`) is Enterprise-gated (spec 14) —
     * moving back down is always allowed regardless of plan, so a lapsed
     * or downgraded org is never stuck unable to turn SSO back off.
     */
    public function update(UpdateAuthModeRequest $request, Team $team, AuthModeTransitioner $transitioner, AuditLogger $auditLogger): RedirectResponse
    {
        Gate::authorize('changeAuthMode', $team);

        $target = AuthMode::from($request->validated('auth_mode'));
        $confirmed = (bool) $request->boolean('confirmed');
        $previous = $team->auth_mode;

        if ($target !== AuthMode::AuthKit) {
            try {
                PlanGate::requireEnterprise($team, 'Enterprise SSO');
            } catch (PlanGateException $exception) {
                throw ValidationException::withMessages(['auth_mode' => $exception->getMessage()]);
            }
        }

        try {
            $transitioner->transition($team, $target, $confirmed);
        } catch (AuthModeTransitionException $exception) {
            throw ValidationException::withMessages(['auth_mode' => $exception->getMessage()]);
        }

        $auditLogger->recordForRequest($request, AuditEventType::AuthModeChanged, $team, (string) $request->user()->id, "{$previous->value} -> {$target->value}");

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Auth mode updated.')]);

        return to_route('teams.authentication.show', ['team' => $team->slug]);
    }
}
