<?php

namespace App\Http\Controllers\Ops\Actions;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Services\Operator\Actions\OperatorActionRecorder;
use App\Services\Operator\Actions\ReindexAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Queues a reindex of one tenant or one artifact (spec 17). Content-blind:
 * the artifact id is checked for shape only and never looked up.
 */
class ReindexController extends Controller
{
    public function __invoke(Request $request, Team $team, ReindexAction $action, OperatorActionRecorder $recorder): RedirectResponse
    {
        $validated = $request->validate([
            'scope' => ['required', Rule::in(['tenant', 'artifact'])],
            'artifact_id' => ['required_if:scope,artifact', 'nullable', 'string', 'regex:/\A[A-Za-z0-9]{10,32}\z/'],
            'reason' => OperatorActionRecorder::REASON_RULES,
        ]);

        $artifactId = $validated['scope'] === 'artifact' ? (string) $validated['artifact_id'] : null;

        $status = $action->run($request, $team, $artifactId, $recorder->reasonFrom($request));

        return redirect()->route('ops.tenants.show', $team)->with('status', $status);
    }
}
