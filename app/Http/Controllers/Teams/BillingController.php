<?php

namespace App\Http\Controllers\Teams;

use App\Enums\AuditEventType;
use App\Enums\BillingPrice;
use App\Enums\PaymentStatus;
use App\Enums\Plan;
use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Services\Billing\BillingService;
use App\Services\Billing\MemberCap;
use App\Services\Billing\PlanGate;
use App\Services\Billing\PlanGateException;
use App\Services\Billing\QuotaService;
use App\Services\Billing\TeamEntitlements;
use App\Services\Billing\TeamTrialService;
use App\Services\Governance\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Spec 14 / RUB-529: flat Pro/Team checkout, custom hostname, trial banner.
 */
class BillingController extends Controller
{
    public function show(Request $request, Team $team, QuotaService $quota, BillingService $billing, TeamTrialService $trials): Response
    {
        abort_unless($request->user()->belongsToTeam($team), 404);
        TeamEntitlements::resolve($team, $request->user())->assertCan(TeamEntitlements::MANAGE_BILLING);

        try {
            $status = $quota->status($team);
            $usage = [
                'storageBytes' => $status->storageBytes,
                'storageLimitBytes' => $status->storageLimitBytes,
                'artifactsThisPeriod' => $status->artifactsThisPeriod,
                'artifactsLimit' => $status->artifactsLimit,
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
        $onTrial = $trials->isActive($team);

        return Inertia::render('teams/billing', [
            'team' => [
                'slug' => $team->slug,
                'name' => $team->name,
                'plan' => $plan->value,
                'paymentStatus' => ($team->payment_status ?? PaymentStatus::Active)->value,
                'hasSubscription' => $team->stripe_subscription_id !== null && ! $onTrial,
                'onTrial' => $onTrial,
                'trialDaysLeft' => $onTrial ? $trials->daysLeft($team) : null,
                'trialEndsAt' => $team->trial_ends_at?->toIso8601String(),
                'seatsBilled' => $team->seats_billed,
                'cancelAtPeriodEnd' => (bool) $team->cancel_at_period_end,
                'renewsAt' => $team->current_period_end?->toIso8601String(),
                'activeSeats' => $billing->activeSeatCount($team),
            ],
            'canManage' => $request->user()->can('manageBilling', $team),
            'isOwner' => $team->owner_user_id === null || $team->owner_user_id === $request->user()->id,
            'stripeConfigured' => (bool) config('services.stripe.secret'),
            'usage' => $usage,
            'limits' => [
                'free' => config('billing.plans.free'),
                'pro' => config('billing.plans.pro'),
                'team' => config('billing.plans.team'),
            ],
            'prices' => [
                'proMonthly' => '$5/month',
                'proYearly' => '$50/year',
                'teamMonthly' => '$49/month for up to 10 people, bots free',
                'teamYearly' => '$490/year for up to 10 people, bots free',
                'yearlySavings' => '2 months free',
                'businessContact' => config('billing.business_contact_email'),
            ],
            'invoices' => $team->stripe_customer_id ? $billing->invoices($team) : [],
            'checkout' => $request->query('checkout'),
        ]);
    }

    public function cancel(Request $request, Team $team, BillingService $billing, AuditLogger $auditLogger): RedirectResponse
    {
        Gate::authorize('manageBilling', $team);
        abort_unless($team->owner_user_id === null || $team->owner_user_id === $request->user()->id, 403, __('Only the team owner can cancel the subscription.'));

        $billing->cancelSubscription($team);
        $auditLogger->recordForRequest($request, AuditEventType::SubscriptionCancelled, $team, (string) $request->user()->id, $team->slug);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Subscription will end at the end of the billing period.')]);

        return to_route('teams.billing.show', ['team' => $team->slug]);
    }

    public function resume(Request $request, Team $team, BillingService $billing, AuditLogger $auditLogger): RedirectResponse
    {
        Gate::authorize('manageBilling', $team);
        abort_unless($team->owner_user_id === null || $team->owner_user_id === $request->user()->id, 403, __('Only the team owner can resume the subscription.'));

        $billing->resumeSubscription($team);
        $auditLogger->recordForRequest($request, AuditEventType::SubscriptionResumed, $team, (string) $request->user()->id, $team->slug);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Subscription resumed.')]);

        return to_route('teams.billing.show', ['team' => $team->slug]);
    }

    public function checkout(Request $request, Team $team, BillingService $billing, TeamTrialService $trials): SymfonyResponse
    {
        Gate::authorize('manageBilling', $team);
        abort_if($team->is_personal, 403, __('Turn this workspace into a team before starting a plan.'));

        $validated = $request->validate([
            'plan' => ['required', Rule::in(['pro', 'team'])],
            'interval' => ['required', Rule::in(['month', 'year'])],
        ]);

        $onTrial = $trials->isActive($team);
        if ($team->stripe_subscription_id !== null && ! $onTrial) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('This team already has a subscription. Use Manage billing to change it.')]);

            return to_route('teams.billing.show', ['team' => $team->slug]);
        }

        $plan = Plan::from($validated['plan']);
        if ($plan === Plan::Pro && MemberCap::humanMemberCount($team) > 1) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('Pro is for one person. Keep Team, or contact :email about Business for larger workspaces.', [
                    'email' => config('billing.business_contact_email', 'support@artfct.dev'),
                ]),
            ]);

            return to_route('teams.billing.show', ['team' => $team->slug]);
        }

        $price = BillingPrice::for($plan, $validated['interval']);
        $url = $billing->startCheckout($team, $price);

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

        try {
            PlanGate::requireTeam($team, 'Custom web address');
        } catch (PlanGateException $exception) {
            return back()->withErrors(['custom_hostname' => $exception->getMessage()]);
        }

        $validated = $request->validate([
            'custom_hostname' => ['required', 'string', 'max:255', 'unique:teams,custom_hostname,'.$team->id],
        ]);

        $team->update(['custom_hostname' => $validated['custom_hostname']]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Custom hostname saved.')]);

        return to_route('teams.edit', ['team' => $team->slug]);
    }
}
