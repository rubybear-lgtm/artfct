<?php

use App\Models\Team;
use App\Models\User;
use App\Services\Billing\UsageContract;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The operator console, exercised in a browser (spec 17).
 *
 * `EnsureStaff` accepts `staff_at` alone in the testing environment, so
 * signing in as a staff user is enough. The Worker's usage answer is the
 * in-process double the Feature suite uses.
 */
function opsConsoleStaff(): User
{
    $staff = User::factory()->create(['name' => 'Ops Staff', 'email' => 'ops-staff@example.com']);
    $staff->forceFill(['staff_at' => now()])->save();

    return $staff;
}

function opsConsoleFakeUsage(): void
{
    app()->instance(UsageContract::class, new class implements UsageContract
    {
        public function currentUsage(string $orgSlug): array
        {
            return [
                'storage_bytes' => 5 * 1024 * 1024,
                'artifacts_this_period' => 42,
                'render_minutes_this_period' => 0,
                'period_start' => '2026-10-01T00:00:00Z',
                'period_end' => '2026-11-01T00:00:00Z',
                'limits' => ['storage_bytes' => 10 * 1024 * 1024 * 1024, 'artifacts_per_month' => 500],
            ];
        }
    });
}

test('staff_finds_a_tenant_and_reads_its_usage', function () {
    Team::factory()->create(['name' => 'Zephyr Laboratories', 'slug' => 'zephyr-labs']);
    Team::factory()->create(['name' => 'Quarry Holdings', 'slug' => 'quarry-holdings']);
    opsConsoleFakeUsage();

    test()->actingAs(opsConsoleStaff());

    visit('/ops')
        ->assertNoJavaScriptErrors()
        ->assertSee('Zephyr Laboratories')
        ->assertSee('Quarry Holdings')
        ->type('input[type="search"]', 'zephyr-labs')
        ->press('Search')
        ->wait(1)
        ->assertSee('Zephyr Laboratories')
        ->assertDontSee('Quarry Holdings')
        ->click('Zephyr Laboratories')
        ->wait(1)
        ->assertPathIs('/ops/tenants/zephyr-labs')
        ->assertSee('Zephyr Laboratories')
        ->assertSee('Artifacts this billing period')
        ->assertSee('42 of 500')
        ->assertSee('5.0 MB of 10.0 GB')
        ->assertNoJavaScriptErrors();
});

test('ops_pages_have_no_js_errors', function () {
    Team::factory()->create(['name' => 'Zephyr Laboratories', 'slug' => 'zephyr-labs']);
    opsConsoleFakeUsage();

    test()->actingAs(opsConsoleStaff());

    foreach (['/ops', '/ops/tenants/zephyr-labs', '/ops/health'] as $path) {
        visit($path)->assertNoJavaScriptErrors();
    }
});
