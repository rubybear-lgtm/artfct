<?php

use App\Models\Team;
use App\Models\User;
use App\Services\FeatureFlags;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('staff can manage a feature release', function () {
    $staff = User::factory()->create();
    $staff->forceFill(['staff_at' => now()])->save();
    Team::factory()->create(['name' => 'Zephyr Laboratories', 'slug' => 'zephyr-labs']);
    config(['features.registry' => ['test-feature' => ['name' => 'Artifact workspaces', 'description' => 'Try the new workspace experience with selected teams.']]]);
    app(FeatureFlags::class)->register();
    $this->actingAs($staff);

    $page = visit('/ops/features');
    $page->assertSee('Artifact workspaces')->assertSee('Off')->click('Artifact workspaces')
        ->assertSee('No release changes yet.')
        ->radio('state', 'selected')
        ->type('#team-slugs', 'zephyr-labs')
        ->type('#release-reason', 'Start the internal team beta')
        ->check('input[type="checkbox"]')
        ->assertEnabled('button[type="submit"]')
        ->press('button[type="submit"]')
        ->assertNoJavaScriptErrors()
        ->assertSee('Feature release updated.')
        ->assertSee('Start the internal team beta')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'feature-flags-after-desktop');

    visit('/ops/features/test-feature')->on()->mobile()
        ->assertSee('Artifact workspaces')->assertSee('Recent changes')->assertNoJavaScriptErrors()
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->screenshot(filename: 'feature-flags-after-mobile');
});

test('staff sees an empty registry without changing existing features', function () {
    $staff = User::factory()->create();
    $staff->forceFill(['staff_at' => now()])->save();
    $this->actingAs($staff);
    visit('/ops/features')->assertSee('No features registered yet')->assertNoJavaScriptErrors();
});
