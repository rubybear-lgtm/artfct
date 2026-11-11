<?php

use App\Enums\PaymentStatus;
use App\Enums\Plan;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Teams\TrialEndingSoon;
use App\Services\Billing\StripeSignature;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    config([
        'services.stripe.webhook_secret' => 'whsec_test',
        'services.worker.base_url' => 'https://worker.test',
        'services.worker.limits_write_secret' => 's',
    ]);
    Http::fake(['worker.test/*' => Http::response(['ok' => true])]);
    Notification::fake();
});

function trialStripeEvent(string $type, array $object, ?string $id = null, ?int $created = null): TestResponse
{
    $body = json_encode([
        'id' => $id ?? 'evt_'.uniqid(),
        'type' => $type,
        'created' => $created ?? time(),
        'data' => ['object' => $object],
    ]);
    $timestamp = time();

    return test()->call('POST', '/webhooks/stripe', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => StripeSignature::header($body, 'whsec_test', $timestamp),
    ], $body);
}

test('subscription_updated_trialing_grants_team_and_mirrors_trial_end', function () {
    $team = Team::factory()->create([
        'plan' => Plan::Free,
        'stripe_customer_id' => 'cus_trial',
    ]);
    $trialEnd = now()->addDays(90)->timestamp;

    trialStripeEvent('customer.subscription.updated', [
        'customer' => 'cus_trial',
        'id' => 'sub_trial',
        'status' => 'trialing',
        'trial_end' => $trialEnd,
        'cancel_at_period_end' => false,
        'current_period_end' => $trialEnd,
    ])->assertOk();

    $team->refresh();
    expect($team->plan)->toBe(Plan::Team)
        ->and($team->payment_status)->toBe(PaymentStatus::Active)
        ->and($team->stripe_subscription_id)->toBe('sub_trial')
        ->and($team->trial_ends_at?->timestamp)->toBe($trialEnd);
});

test('trial_will_end_sends_reminder_and_returns_ok', function () {
    $owner = User::factory()->create(['email' => 'owner@example.com']);
    $team = Team::factory()->create([
        'plan' => Plan::Team,
        'stripe_customer_id' => 'cus_remind',
        'stripe_subscription_id' => 'sub_remind',
        'owner_user_id' => $owner->id,
        'trial_started_at' => now()->subDays(87),
        'trial_ends_at' => now()->addDays(3),
    ]);
    $team->memberships()->create(['user_id' => $owner->id, 'role' => TeamRole::Admin]);

    trialStripeEvent('customer.subscription.trial_will_end', [
        'customer' => 'cus_remind',
        'id' => 'sub_remind',
        'status' => 'trialing',
        'trial_end' => $team->trial_ends_at->timestamp,
    ])->assertOk()->assertJson(['status' => 'ok']);

    Notification::assertSentTo($owner, TrialEndingSoon::class);
    expect($team->fresh()->trial_reminder_sent_at)->not->toBeNull();
});

test('subscription_deleted_after_trial_drops_to_free_without_charge', function () {
    $team = Team::factory()->create([
        'plan' => Plan::Team,
        'stripe_customer_id' => 'cus_end',
        'stripe_subscription_id' => 'sub_end',
        'trial_started_at' => now()->subDays(90),
        'trial_ends_at' => now()->subSecond(),
    ]);

    trialStripeEvent('customer.subscription.deleted', [
        'customer' => 'cus_end',
        'id' => 'sub_end',
        'status' => 'canceled',
    ])->assertOk();

    $team->refresh();
    expect($team->plan)->toBe(Plan::Free)
        ->and($team->stripe_subscription_id)->toBeNull()
        ->and($team->trial_started_at)->not->toBeNull();
});

test('unknown_event_types_including_trial_noise_still_return_2xx', function () {
    trialStripeEvent('customer.subscription.trial_will_end', ['customer' => 'cus_none'])->assertOk();
    trialStripeEvent('charge.dispute.created', [])->assertOk();
    trialStripeEvent('invoice.created', ['customer' => 'cus_x'])->assertOk();
});
