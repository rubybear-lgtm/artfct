<?php

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->withoutVite();
    config(['inertia.testing.ensure_pages_exist' => false]);
});

function tenantListStaff(): User
{
    $staff = User::factory()->create();
    $staff->forceFill(['staff_at' => now()])->save();

    return $staff;
}

/**
 * @param  array<string, mixed>  $attributes  Columns outside the team's fillable list.
 */
function tenantListTeam(string $slug, array $attributes = []): Team
{
    $team = Team::factory()->create(['slug' => $slug, 'name' => ucfirst($slug)]);
    $team->forceFill($attributes)->save();

    return $team;
}

/**
 * The row slugs of one tenant list page, sorted.
 *
 * @param  array<string, mixed>  $query
 * @return list<string>
 */
function tenantListSlugs(array $query): array
{
    $response = test()->actingAs(tenantListStaff())->get(route('ops.tenants.index', $query))->assertOk();

    $slugs = array_column($response->viewData('page')['props']['tenants']['data'], 'slug');
    sort($slugs);

    return $slugs;
}

test('tenant_list_filters_by_plan_payment_status_region', function () {
    tenantListTeam('alpha', ['plan' => 'team', 'payment_status' => 'active', 'region' => 'eu']);
    tenantListTeam('bravo', ['plan' => 'enterprise', 'payment_status' => 'past_due', 'region' => 'us']);
    tenantListTeam('charlie', ['plan' => 'free', 'payment_status' => 'active', 'region' => 'eu']);
    tenantListTeam('delta', ['plan' => 'team', 'payment_status' => 'past_due', 'region' => 'eu']);

    expect(tenantListSlugs([]))->toBe(['alpha', 'bravo', 'charlie', 'delta'])
        ->and(tenantListSlugs(['plan' => 'team']))->toBe(['alpha', 'delta'])
        ->and(tenantListSlugs(['plan' => 'enterprise']))->toBe(['bravo'])
        ->and(tenantListSlugs(['payment_status' => 'past_due']))->toBe(['bravo', 'delta'])
        ->and(tenantListSlugs(['region' => 'eu']))->toBe(['alpha', 'charlie', 'delta'])
        ->and(tenantListSlugs(['region' => 'us']))->toBe(['bravo'])
        ->and(tenantListSlugs(['plan' => 'team', 'payment_status' => 'past_due', 'region' => 'eu']))->toBe(['delta']);

    $response = test()->actingAs(tenantListStaff())
        ->get(route('ops.tenants.index', ['plan' => 'team', 'region' => 'eu']))
        ->assertOk();
    $props = $response->viewData('page')['props'];

    expect($props['filters'])->toMatchArray(['plan' => 'team', 'region' => 'eu', 'payment_status' => null, 'deleted' => false])
        ->and($props['options']['regions'])->toBe(['eu', 'us'])
        ->and($props['options']['plans'])->toBe(['free', 'team', 'enterprise'])
        ->and($props['tenants']['data'][0])->toHaveKeys([
            'slug', 'name', 'plan', 'payment_status', 'seats', 'region', 'release_version', 'schema_version', 'created_at', 'state',
        ]);
});

test('tenant_list_state_filters_and_deleted_tenants', function () {
    tenantListTeam('healthy');
    tenantListTeam('stuck', ['provisioning_failed_step' => 'create_database']);
    tenantListTeam('leaving', ['cancel_at_period_end' => true]);
    tenantListTeam('overdue', ['payment_status' => 'past_due']);
    tenantListTeam('gone')->delete();

    expect(tenantListSlugs([]))->toBe(['healthy', 'leaving', 'overdue', 'stuck'])
        ->and(tenantListSlugs(['provisioning_failed' => 1]))->toBe(['stuck'])
        ->and(tenantListSlugs(['cancelling' => 1]))->toBe(['leaving'])
        ->and(tenantListSlugs(['past_due' => 1]))->toBe(['overdue'])
        ->and(tenantListSlugs(['deleted' => 1]))->toBe(['gone']);

    $rows = test()->actingAs(tenantListStaff())->get(route('ops.tenants.index', ['deleted' => 1]))
        ->viewData('page')['props']['tenants']['data'];

    expect($rows[0]['state'])->toBe('deleted');
});

test('tenant_list_search_matches_slug_email_and_stripe_id', function () {
    // No name contains its slug, email or Stripe id, so each term below can
    // only match through the one column it is aimed at.
    tenantListTeam('acme-widgets', ['name' => 'Zeta Holdings', 'billing_email' => 'billing@one.example', 'stripe_customer_id' => 'cus_OneCustomer1']);
    tenantListTeam('globex', ['name' => 'Yankee Corp', 'billing_email' => 'accounts@globex-corp.example', 'stripe_customer_id' => 'cus_TwoCustomer2']);
    tenantListTeam('initech', ['name' => 'Xray Ltd', 'billing_email' => 'pay@three.example', 'stripe_customer_id' => 'cus_ThreeCustomer3']);

    expect(tenantListSlugs(['q' => 'acme-widgets']))->toBe(['acme-widgets'])
        ->and(tenantListSlugs(['q' => 'ACME-WIDGETS']))->toBe(['acme-widgets'])
        ->and(tenantListSlugs(['q' => 'globex-corp.example']))->toBe(['globex'])
        ->and(tenantListSlugs(['q' => 'accounts@globex-corp.example']))->toBe(['globex'])
        ->and(tenantListSlugs(['q' => 'cus_ThreeCustomer3']))->toBe(['initech'])
        ->and(tenantListSlugs(['q' => 'Yankee']))->toBe(['globex'])
        ->and(tenantListSlugs(['q' => 'no-such-tenant']))->toBe([]);
});

test('tenant_list_search_treats_wildcards_as_literal_text', function () {
    tenantListTeam('acme-widgets', ['name' => 'Zeta Holdings', 'billing_email' => 'billing@one.example', 'stripe_customer_id' => 'cus_OneCustomer1']);
    tenantListTeam('globex', ['name' => 'Yankee Corp', 'billing_email' => 'accounts@two.example', 'stripe_customer_id' => 'cus_TwoCustomer2']);
    tenantListTeam('under_score', ['name' => 'Xray Ltd', 'billing_email' => '100%@three.example', 'stripe_customer_id' => 'cus_ThreeCustomer3']);

    expect(tenantListSlugs(['q' => '%']))->toBe(['under_score'])
        ->and(tenantListSlugs(['q' => '100%']))->toBe(['under_score'])
        ->and(tenantListSlugs(['q' => 'under_score']))->toBe(['under_score'])
        // `_` would match any single character if it were left unescaped.
        ->and(tenantListSlugs(['q' => 'under-score']))->toBe([])
        ->and(tenantListSlugs(['q' => 'c_s']))->toBe([])
        ->and(tenantListSlugs(['q' => 'cus_Two']))->toBe(['globex'])
        ->and(tenantListSlugs(['q' => 'a!b']))->toBe([]);
});

test('tenant_list_cursor_pagination_has_no_gaps_or_duplicates', function () {
    $staff = tenantListStaff();

    foreach (range(1, 60) as $number) {
        Team::factory()->create(['slug' => sprintf('tenant-%02d', $number)]);
    }

    // Most rows share one timestamp, so the cursor can only be stable if the
    // id breaks the tie. The rest straddle it on both sides.
    DB::table('teams')->update(['created_at' => '2026-03-01 12:00:00']);
    DB::table('teams')->where('slug', '<', 'tenant-10')->update(['created_at' => '2026-04-01 12:00:00']);
    DB::table('teams')->where('slug', '>', 'tenant-55')->update(['created_at' => '2026-01-01 12:00:00']);

    $seen = [];
    $cursor = null;
    $pages = 0;

    do {
        $response = test()->actingAs($staff)
            ->get(route('ops.tenants.index', $cursor === null ? [] : ['cursor' => $cursor]))
            ->assertOk();
        $tenants = $response->viewData('page')['props']['tenants'];

        expect(count($tenants['data']))->toBeLessThanOrEqual(25);

        foreach ($tenants['data'] as $row) {
            $seen[] = $row['slug'];
        }

        $cursor = $tenants['next_cursor'];
        $pages++;
    } while ($cursor !== null && $pages < 10);

    $expected = array_map(fn (int $number): string => sprintf('tenant-%02d', $number), range(1, 60));

    expect($pages)->toBe(3)
        ->and($seen)->toHaveCount(60)
        ->and(array_unique($seen))->toHaveCount(60)
        ->and(collect($seen)->sort()->values()->all())->toBe($expected);
});
