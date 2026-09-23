<?php

use App\Enums\Plan;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Services\Billing\FakeUsage;
use App\Services\Billing\RealUsage;
use App\Services\Billing\UsageContract;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

function bannerTeam(int $storageBytes, int $artifacts): array
{
    Cache::flush();
    $team = Team::factory()->create(['plan' => Plan::Free]);
    $member = memberOfTeam($team, TeamRole::Member);
    $member->switchTeam($team);
    /** @var FakeUsage $usage */
    $usage = app(UsageContract::class);
    $usage->setUsage($team->slug, $storageBytes, $artifacts);

    return [$team, $member];
}

test('no_banner_flags_when_usage_is_healthy', function () {
    [$team, $member] = bannerTeam(0, 0);

    test()->actingAs($member)->get(route('dashboard', $team))
        ->assertInertia(fn (Assert $page) => $page->where('quota.warning', false)->where('quota.exceeded', false));
});

test('a_warning_is_shared_at_eighty_percent_of_a_limit', function () {
    $limit = (int) config('billing.plans.free.artifacts_per_month');
    [$team, $member] = bannerTeam(0, (int) ceil($limit * 0.85));

    test()->actingAs($member)->get(route('dashboard', $team))
        ->assertInertia(fn (Assert $page) => $page->where('quota.warning', true)->where('quota.exceeded', false));
});

test('exceeded_is_shared_at_the_limit', function () {
    $limit = (int) config('billing.plans.free.artifacts_per_month');
    [$team, $member] = bannerTeam(0, $limit);

    test()->actingAs($member)->get(route('dashboard', $team))
        ->assertInertia(fn (Assert $page) => $page->where('quota.exceeded', true));
});

test('an_unreachable_worker_degrades_to_no_banner_not_an_error', function () {
    Cache::flush();
    $team = Team::factory()->create();
    $member = memberOfTeam($team, TeamRole::Member);
    $member->switchTeam($team);
    app()->instance(UsageContract::class, new class implements UsageContract
    {
        public function currentUsage(string $orgSlug): array
        {
            throw new RuntimeException('worker down');
        }
    });

    test()->actingAs($member)->get(route('dashboard', $team))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('quota', null));
});

test('the_meter_uses_the_limits_the_worker_reports_over_the_plans', function () {
    Cache::flush();
    $team = Team::factory()->create(['plan' => Plan::Free]);
    $member = memberOfTeam($team, TeamRole::Member);
    $member->switchTeam($team);
    app()->instance(UsageContract::class, new class implements UsageContract
    {
        public function currentUsage(string $orgSlug): array
        {
            return [
                'storage_bytes' => 900,
                'artifacts_this_period' => 0,
                'render_minutes_this_period' => 0,
                'limits' => ['storage_bytes' => 1000, 'artifacts_per_month' => 50],
            ];
        }
    });

    test()->actingAs($member)->get(route('dashboard', $team))
        ->assertInertia(fn (Assert $page) => $page->where('quota.warning', true)->where('quota.exceeded', false));
});

test('real_usage_returns_the_limits_the_worker_enforces', function () {
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake(['worker.test/*' => Http::response([
        'storage_bytes' => 10, 'artifacts_this_period' => 2, 'limits' => ['storage_bytes' => 12, 'artifacts_per_month' => 5],
    ])]);
    configureSigning(testSigningKey());

    $usage = (new RealUsage)->currentUsage('acme');

    expect($usage['limits'])->toBe(['storage_bytes' => 12, 'artifacts_per_month' => 5]);
});

test('billing_exposes_worker_usage_and_limits_for_precise_meters', function () {
    $team = Team::factory()->create(['plan' => Plan::Free]);
    $member = memberOfTeam($team, TeamRole::Member);
    $member->switchTeam($team);
    app()->instance(UsageContract::class, new class implements UsageContract
    {
        public function currentUsage(string $orgSlug): array
        {
            return [
                'storage_bytes' => 900,
                'artifacts_this_period' => 4,
                'render_minutes_this_period' => 0,
                'limits' => ['storage_bytes' => 1000, 'artifacts_per_month' => 5],
            ];
        }
    });

    test()->actingAs($member)->get(route('teams.billing.show', $team))
        ->assertInertia(fn (Assert $page) => $page
            ->where('usage.storageBytes', 900)
            ->where('usage.storageLimitBytes', 1000)
            ->where('usage.artifactsThisPeriod', 4)
            ->where('usage.artifactsLimit', 5));
});
