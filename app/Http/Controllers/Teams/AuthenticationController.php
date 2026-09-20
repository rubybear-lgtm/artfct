<?php

namespace App\Http\Controllers\Teams;

use App\Enums\AuditEventType;
use App\Enums\AuthMode;
use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Services\Billing\PlanGate;
use App\Services\Governance\AuditLogger;
use App\Services\Identity\AuthModeTransitioner;
use App\Services\Polis\PolisAdminClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The "Authentication" settings page: verified domains, the sign-in mode
 * with a dry-run of what each change would do, and the org's SSO connection.
 * SSO is Enterprise-only; moving back to plain sign-in is always allowed.
 */
class AuthenticationController extends Controller
{
    public function show(Request $request, Team $team, AuthModeTransitioner $transitioner, PolisAdminClient $polis): Response
    {
        abort_unless($request->user()->belongsToTeam($team), 404);
        Gate::authorize('changeAuthMode', $team);

        try {
            $connections = $polis->connections($team->slug);
        } catch (\Throwable) {
            $connections = null;
        }

        return Inertia::render('teams/authentication', [
            'team' => ['slug' => $team->slug, 'name' => $team->name],
            'isEnterprise' => PlanGate::isEnterprise($team),
            'authMode' => $team->auth_mode->value,
            'domains' => $team->domains()->get()->map(fn ($domain): array => [
                'id' => $domain->id,
                'domain' => $domain->domain,
                'verificationToken' => $domain->verification_token,
                'txtRecordName' => $domain->txtRecordName(),
                'verified' => $domain->verified_at !== null,
            ]),
            'previews' => collect(AuthMode::cases())->mapWithKeys(fn (AuthMode $mode): array => [
                $mode->value => $transitioner->preview($team, $mode),
            ]),
            'connections' => $connections,
        ]);
    }

    public function storeConnection(Request $request, Team $team, PolisAdminClient $polis, AuditLogger $auditLogger): RedirectResponse
    {
        abort_unless($request->user()->belongsToTeam($team), 404);
        Gate::authorize('changeAuthMode', $team);
        abort_unless(PlanGate::isEnterprise($team), 403, __('Enterprise SSO is an Enterprise feature.'));

        $validated = $request->validate(['metadata_url' => ['required', 'url:https', 'max:2048']]);

        try {
            $polis->createSamlConnection($team->slug, $validated['metadata_url'], route('sso.authenticate', ['team' => $team->slug]));
        } catch (\Throwable) {
            throw ValidationException::withMessages(['metadata_url' => __('Polis could not create the connection from that metadata URL.')]);
        }

        $auditLogger->recordForRequest($request, AuditEventType::AuthModeChanged, $team, (string) $request->user()->id, 'sso connection added');

        return back();
    }

    public function destroyConnection(Request $request, Team $team, PolisAdminClient $polis, AuditLogger $auditLogger): RedirectResponse
    {
        abort_unless($request->user()->belongsToTeam($team), 404);
        Gate::authorize('changeAuthMode', $team);
        abort_if($team->auth_mode === AuthMode::Polis, 422, __('Switch off SSO-only sign-in before removing the connection.'));

        $polis->deleteConnections($team->slug);
        $auditLogger->recordForRequest($request, AuditEventType::AuthModeChanged, $team, (string) $request->user()->id, 'sso connection removed');

        return back();
    }
}
