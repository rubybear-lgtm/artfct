<?php

use App\Enums\Plan;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Services\Billing\FakeUsage;
use App\Services\Billing\UsageContract;
use Illuminate\Support\Facades\Cache;
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
