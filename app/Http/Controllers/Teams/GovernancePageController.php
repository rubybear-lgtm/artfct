<?php

namespace App\Http\Controllers\Teams;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Services\Billing\PlanGate;
use App\Services\Billing\PlanGateException;
use App\Services\Governance\ArtifactGovernanceContract;
use App\Services\Governance\LegalHoldService;
use App\Services\Governance\RetentionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Retention and legal hold, for governance admins. Deletion itself is never
 * a button here: the page shows the policy, previews what the retention job
 * would remove (a dry run), and places or releases legal holds. Running the
 * job and GDPR erasure stay operator commands (`governance:*`).
 */
class GovernancePageController extends Controller
{
    public function show(Request $request, Team $team, ArtifactGovernanceContract $governance): Response
    {
        $this->authorizeAdmin($request, $team);

        try {
            $held = collect($governance->listAllArtifacts($team->slug))
                ->filter(fn (array $artifact): bool => $artifact['legal_hold'])
                ->pluck('id')
                ->values()
                ->all();
        } catch (\Throwable) {
            $held = null;
        }

        return Inertia::render('teams/governance', [
            'team' => ['slug' => $team->slug, 'name' => $team->name],
            'retentionDays' => $team->retention_days,
            'defaultRetentionDays' => RetentionService::defaultRetentionDays(),
            'isEnterprise' => PlanGate::isEnterprise($team),
            'heldArtifacts' => $held,
            'preview' => $request->session()->get('retention_preview'),
        ]);
    }

    public function preview(Request $request, Team $team, RetentionService $retention): RedirectResponse
    {
        $this->authorizeAdmin($request, $team);

        try {
            $plan = $retention->apply($team, null, dryRun: true, actor: (string) $request->user()->id);
            $preview = ['wouldDelete' => count($plan->toDelete), 'heldSurvivors' => count($plan->heldSurvivors)];
        } catch (\Throwable) {
            $preview = ['error' => __('The preview is unavailable right now.')];
        }

        return to_route('teams.governance.show', $team)->with('retention_preview', $preview);
    }

    public function placeHold(Request $request, Team $team, LegalHoldService $holds): RedirectResponse
    {
        $this->authorizeAdmin($request, $team);
        $artifactId = $request->validate(['artifact_id' => ['required', 'string', 'max:64']])['artifact_id'];

        try {
            $holds->place($team, $artifactId, (string) $request->user()->id);
        } catch (PlanGateException $exception) {
            throw ValidationException::withMessages(['artifact_id' => $exception->getMessage()]);
        }

        return back();
    }

    public function releaseHold(Request $request, Team $team, string $artifactId, LegalHoldService $holds): RedirectResponse
    {
        $this->authorizeAdmin($request, $team);

        try {
            $holds->release($team, $artifactId, (string) $request->user()->id);
        } catch (PlanGateException $exception) {
            throw ValidationException::withMessages(['artifact_id' => $exception->getMessage()]);
        }

        return back();
    }

    private function authorizeAdmin(Request $request, Team $team): void
    {
        abort_unless($request->user()->belongsToTeam($team), 404);
        Gate::authorize('manageGovernance', $team);
    }
}
