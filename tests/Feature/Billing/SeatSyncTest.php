<?php

use App\Enums\AuditEventType;
use App\Enums\Plan;
use App\Enums\TeamRole;
use App\Models\AuditEvent;
use App\Models\Team;
use App\Services\Billing\BillingContract;

function subscribedTeam(int $seatsBilled = 1): Team
{
    $team = Team::factory()->create(['plan' => Plan::Team, 'stripe_subscription_id' => 'sub_'.uniqid(), 'seats_billed' => $seatsBilled]);
    memberOfTeam($team, TeamRole::Admin);

    return $team;
}

beforeEach(function () {
    config(['services.worker.base_url' => null]);
});

test('sync_updates_quantity_to_active_members', function () {
    $team = subscribedTeam();
    memberOfTeam($team, TeamRole::Member);
    memberOfTeam($team, TeamRole::Viewer);

    test()->artisan('billing:sync-seats', ['org' => $team->slug])->assertSuccessful();

    expect($team->fresh()->seats_billed)->toBe(3)
        ->and(AuditEvent::query()->where('team_id', $team->id)->where('event_type', AuditEventType::SeatsSynced)->count())->toBe(1);
});

test('sync_is_idempotent', function () {
    $team = subscribedTeam();
    memberOfTeam($team, TeamRole::Member);

    test()->artisan('billing:sync-seats', ['org' => $team->slug])->assertSuccessful();
    test()->artisan('billing:sync-seats', ['org' => $team->slug])->assertSuccessful();

    expect(AuditEvent::query()->where('team_id', $team->id)->where('event_type', AuditEventType::SeatsSynced)->count())->toBe(1);
});

test('team_without_subscription_is_skipped', function () {
    $team = Team::factory()->create();
    memberOfTeam($team, TeamRole::Admin);

    test()->artisan('billing:sync-seats')->assertSuccessful();

    expect($team->fresh()->seats_billed)->toBeNull();
});

test('deactivated_members_are_not_counted', function () {
    $team = subscribedTeam();
    $member = memberOfTeam($team, TeamRole::Member);
    $member->forceFill(['deactivated_at' => now()])->save();

    test()->artisan('billing:sync-seats', ['org' => $team->slug])->assertSuccessful();

    expect($team->fresh()->seats_billed)->toBe(1);
});

test('viewers_can_be_excluded_by_config', function () {
    config(['billing.viewers_billable' => false]);
    $team = subscribedTeam();
    memberOfTeam($team, TeamRole::Viewer);

    test()->artisan('billing:sync-seats', ['org' => $team->slug])->assertSuccessful();

    expect($team->fresh()->seats_billed)->toBe(1);
});

test('one_failure_does_not_stop_the_others', function () {
    $failing = subscribedTeam();
    $ok = subscribedTeam();
    memberOfTeam($ok, TeamRole::Member);

    $failingSubscription = $failing->stripe_subscription_id;
    app()->instance(BillingContract::class, new class($failingSubscription) implements BillingContract
    {
        public function __construct(private string $failingSubscription) {}

        public function createCheckoutSession(Team $team, int $seatCount): string
        {
            return 'https://checkout.test';
        }

        public function updateSeats(string $subscriptionId, int $seatCount): void
        {
            if ($subscriptionId === $this->failingSubscription) {
                throw new RuntimeException('stripe down');
            }
        }

        public function cancelSubscription(string $subscriptionId): void {}

        public function resumeSubscription(string $subscriptionId): void {}

        public function listInvoices(Team $team): array
        {
            return [];
        }

        public function createPortalSession(Team $team): string
        {
            return 'https://portal.test';
        }
    });
    $failing->forceFill(['seats_billed' => 99])->save();

    test()->artisan('billing:sync-seats')->assertFailed();

    expect($ok->fresh()->seats_billed)->toBe(2);
});
