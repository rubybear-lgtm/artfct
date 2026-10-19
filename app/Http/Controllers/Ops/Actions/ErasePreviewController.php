<?php

namespace App\Http\Controllers\Ops\Actions;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Services\Operator\Actions\EraseTenantAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Runs the erasure dry run and shows the plan (spec 17). Read-only and not
 * logged as a view; the plan carries counts, never artifact ids.
 */
class ErasePreviewController extends Controller
{
    public function __invoke(Request $request, Team $team, EraseTenantAction $action): RedirectResponse
    {
        $action->preview($request, $team);

        return redirect()->route('ops.tenants.show', $team);
    }
}
