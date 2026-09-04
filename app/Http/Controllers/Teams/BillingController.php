<?php

namespace App\Http\Controllers\Teams;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Services\Billing\BillingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Spec 14: checkout and custom hostname. Stripe webhook processing
 * (`checkout.session.completed`, `invoice.payment_failed`,
 * `invoice.payment_succeeded`) is applied through `BillingService`'s
 * `applyCheckoutCompleted`/`applyPaymentFailed`/`applyPaymentSucceeded` —
 * a real webhook-receiving route that verifies Stripe's signature and
 * calls these was not built this session (no live Stripe account to sign
 * against); see DOCUMENTATION.md.
 */
class BillingController extends Controller
{
    public function checkout(Team $team, BillingService $billing): RedirectResponse
    {
        Gate::authorize('manageBilling', $team);

        $url = $billing->startCheckout($team);

        return redirect()->away($url);
    }

    public function updateHostname(Request $request, Team $team): RedirectResponse
    {
        Gate::authorize('manageBilling', $team);

        $validated = $request->validate([
            'custom_hostname' => ['required', 'string', 'max:255', 'unique:teams,custom_hostname,'.$team->id],
        ]);

        $team->update(['custom_hostname' => $validated['custom_hostname']]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Custom hostname saved.')]);

        return to_route('teams.edit', ['team' => $team->slug]);
    }
}
