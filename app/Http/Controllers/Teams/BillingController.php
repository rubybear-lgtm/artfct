<?php

namespace App\Http\Controllers\Teams;

use App\Enums\PaymentStatus;
use App\Enums\Plan;
use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Services\Billing\BillingService;
use App\Services\Billing\QuotaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

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
    public function show(Request $request, Team $team, QuotaService $quota, BillingService $billing): Response
    {
        abort_unless($request->user()->belongsToTeam($team), 404);

        try {
            $status = $quota->status($team);
            $usage = [
                'storagePercent' => round($status->storagePercent * 100, 1),
                'artifactsPercent' => round($status->artifactsPercent * 100, 1),
                'storageWarning' => $status->storageWarning,
                'artifactsWarning' => $status->artifactsWarning,
                'storageExceeded' => $status->storageExceeded,
                'artifactsExceeded' => $status->artifactsExceeded,
            ];
        } catch (\Throwable) {
            $usage = null;
        }

        $plan = $team->plan ?? Plan::Free;

        return Inertia::render('teams/billing', [
            'team' => [
                'slug' => $team->slug,
                'name' => $team->name,
                'plan' => $plan->value,
                'paymentStatus' => ($team->payment_status ?? PaymentStatus::Active)->value,
                'hasSubscription' => $team->stripe_subscription_id !== null,
                'seatsBilled' => $team->seats_billed,
                'activeSeats' => $billing->activeSeatCount($team),
            ],
            'canManage' => $request->user()->can('manageBilling', $team),
            'isOwner' => $team->owner_user_id === null || $team->owner_user_id === $request->user()->id,
            'stripeConfigured' => (bool) config('services.stripe.secret') && (bool) config('services.stripe.team_price_id'),
            'usage' => $usage,
            'limits' => [
                'free' => config('billing.plans.free'),
                'team' => config('billing.plans.team'),
            ],
            'checkout' => $request->query('checkout'),
        ]);
    }

    public function cancel(Request $request, Team $team, BillingService $billing): RedirectResponse
    {
        Gate::authorize('manageBilling', $team);
        abort_unless($team->owner_user_id === null || $team->owner_user_id === $request->user()->id, 403, __('Only the team owner can cancel the subscription.'));

        $billing->cancelSubscription($team);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Subscription will end at the end of the billing period.')]);

        return to_route('teams.billing.show', ['team' => $team->slug]);
    }

    public function checkout(Team $team, BillingService $billing): SymfonyResponse
    {
        Gate::authorize('manageBilling', $team);

        $url = $billing->startCheckout($team);

        return Inertia::location($url);
    }

    public function portal(Team $team, BillingService $billing): SymfonyResponse
    {
        Gate::authorize('manageBilling', $team);

        return Inertia::location($billing->portalUrl($team));
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
