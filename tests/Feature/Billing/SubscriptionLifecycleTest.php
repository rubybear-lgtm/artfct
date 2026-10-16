<?php

use App\Enums\AuditEventType;
use App\Enums\Plan;
use App\Enums\TeamRole;
use App\Models\AuditEvent;
use App\Models\Team;
use App\Services\Billing\BillingContract;
use App\Services\Billing\FakeBilling;
use Inertia\Testing\AssertableInertia as Assert;

function paidTeam(): array
{
    $team = Team::factory()->create(['plan' => Plan::Team, 'stripe_subscription_id' => 'sub_1', 'stripe_customer_id' => 'cus_1']);
    $owner = memberOfTeam($team, TeamRole::Admin);
    $team->forceFill(['owner_user_id' => $owner->id])->save();

    return [$team, $owner];
}

test('cancel_schedules_cancellation_and_resume_reverses_it_both_audited', function () {
    [$team, $owner] = paidTeam();
    /** @var FakeBilling $billing */
    $billing = app(BillingContract::class);
    $billing->subscriptions['sub_1'] = ['team_slug' => $team->slug, 'seat_count' => 1, 'cancelled' => false];

    test()->actingAs($owner)->post(route('teams.billing.cancel', $team))->assertRedirect();
    expect($team->fresh()->cancel_at_period_end)->toBeTrue()->and($billing->subscriptions['sub_1']['cancelled'])->toBeTrue();

    test()->actingAs($owner)->post(route('teams.billing.resume', $team))->assertRedirect();
    expect($team->fresh()->cancel_at_period_end)->toBeFalse()->and($billing->subscriptions['sub_1']['cancelled'])->toBeFalse();

    foreach ([AuditEventType::SubscriptionCancelled, AuditEventType::SubscriptionResumed] as $type) {
        expect(AuditEvent::query()->where('team_id', $team->id)->where('event_type', $type)->exists())->toBeTrue();
    }
});

test('only_the_owner_can_resume', function () {
    [$team] = paidTeam();
    $otherAdmin = memberOfTeam($team, TeamRole::Admin);

    test()->actingAs($otherAdmin)->post(route('teams.billing.resume', $team))->assertForbidden();
});

test('the_billing_page_shows_invoices_and_the_cancel_state', function () {
    [$team, $owner] = paidTeam();
    $team->forceFill(['cancel_at_period_end' => true])->save();
    /** @var FakeBilling $billing */
    $billing = app(BillingContract::class);
    $billing->invoices = [['number' => 'INV-1', 'amount' => 1200, 'currency' => 'usd', 'status' => 'paid', 'date' => now()->timestamp, 'url' => 'https://invoice.test/1']];

    test()->actingAs($owner)->get(route('teams.billing.show', $team))
        ->assertInertia(fn (Assert $page) => $page
            ->where('team.cancelAtPeriodEnd', true)
            ->where('invoices.0.number', 'INV-1'));
});

test('the_billing_page_hides_invoices_for_a_team_without_a_customer', function () {
    $team = Team::factory()->create();
    $admin = memberOfTeam($team, TeamRole::Admin);
    app(BillingContract::class)->invoices = [['number' => 'X', 'amount' => 1, 'currency' => 'usd', 'status' => 'paid', 'date' => 1, 'url' => null]];

    test()->actingAs($admin)->get(route('teams.billing.show', $team))
        ->assertInertia(fn (Assert $page) => $page->where('invoices', []));
});
