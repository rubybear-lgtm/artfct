<?php

namespace App\Http\Controllers\Teams;

use App\Enums\AuditEventType;
use App\Enums\AuthMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Teams\UpdateAuthModeRequest;
use App\Models\Team;
use App\Services\Auth\OrgTokenRevoker;
use App\Services\Billing\PlanGate;
use App\Services\Billing\PlanGateException;
use App\Services\Governance\AuditLogger;
use App\Services\Identity\AuthModeTransitioner;
use App\Services\Identity\AuthModeTransitionException;
use App\Support\TeamSignIn;
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
    public function update(UpdateAuthModeRequest $request, Team $team, AuthModeTransitioner $transitioner, AuditLogger $auditLogger, OrgTokenRevoker $tokenRevoker): RedirectResponse
    {
        Gate::authorize('changeAuthMode', $team);

        $target = AuthMode::from($request->validated('auth_mode'));
        $confirmed = (bool) $request->boolean('confirmed');
        $previous = $team->auth_mode;

        // Switching to SSO-only from a session that did not sign in through SSO would
        // lock this admin out of the settings they are using (RUB-458).
        if ($target === AuthMode::Polis && ! TeamSignIn::provesPolisSignIn($request, $request->user(), $team)) {
            throw ValidationException::withMessages(['auth_mode' => __("Sign in with your organisation's SSO before switching to SSO-only, so you keep access to these settings.")]);
        }

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

        // AuthKit-minted MCP refresh tokens and org tokens keep working until
        // revoked; refuse the switch if the Worker denylist write fails so
        // SSO-only never leaves sessionless AuthKit credentials live (RUB-482).
        if ($target === AuthMode::Polis && $previous !== AuthMode::Polis) {
            if (! $tokenRevoker->revokeNonPolisCredentialsForTeam($team)) {
                $team->forceFill(['auth_mode' => $previous])->save();

                throw ValidationException::withMessages([
                    'auth_mode' => __('Unable to revoke existing MCP credentials at the Worker. Auth mode was not changed; please retry shortly.'),
                ]);
            }
        }

        $auditLogger->recordForRequest($request, AuditEventType::AuthModeChanged, $team, (string) $request->user()->id, "{$previous->value} -> {$target->value}");

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Auth mode updated.')]);

        return to_route('teams.authentication.show', ['team' => $team->slug]);
    }
}
