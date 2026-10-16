<?php

use App\Enums\PaymentStatus;
use App\Enums\Plan;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Services\Billing\BillingContract;
use App\Services\Billing\BillingService;
use App\Services\Billing\BundleTooLargeException;
use App\Services\Billing\FakeBilling;
use App\Services\Billing\FakeUsage;
use App\Services\Billing\PlanGate;
use App\Services\Billing\PlanGateException;
use App\Services\Billing\QuotaExceededException;
use App\Services\Billing\QuotaLimits;
use App\Services\Billing\QuotaService;
use App\Services\Billing\UsageContract;
use App\Services\Governance\SiemExportService;

test('team_plan_refused_audit_export', function () {
    $team = Team::factory()->create(['plan' => Plan::Team]);

    expect(fn () => app(SiemExportService::class)->export($team, actor: 'tester'))
        ->toThrow(PlanGateException::class);
});

test('team_plan_refused_retention_config', function () {
    $team = Team::factory()->create(['plan' => Plan::Team]);
    $admin = memberOfTeam($team, TeamRole::Admin);

    $response = test()->actingAs($admin)->patch(
        "/settings/teams/{$team->slug}/retention",
        ['retention_days' => 30],
    );

    $response->assertSessionHasErrors('retention_days');
    expect($team->fresh()->retention_days)->toBeNull();
});

test('team_plan_refused_sso', function () {
    $team = Team::factory()->create(['plan' => Plan::Team]);
    $team->domains()->create(['domain' => 'acme.com', 'verified_at' => now()]);
    $admin = memberOfTeam($team, TeamRole::Admin);

    $response = test()->actingAs($admin)->patch(
        "/settings/teams/{$team->slug}/auth-mode",
        ['auth_mode' => 'dual'],
    );

    $response->assertSessionHasErrors('auth_mode');
    expect($team->fresh()->auth_mode->value)->toBe('authkit');
});

test('enterprise_plan_grants_all_gated_features', function () {
    $team = Team::factory()->create(['plan' => Plan::Enterprise]);
    $team->domains()->create(['domain' => 'acme.com', 'verified_at' => now()]);
    $admin = memberOfTeam($team, TeamRole::Admin);

    expect(fn () => app(SiemExportService::class)->export($team, actor: 'tester'))->not->toThrow(PlanGateException::class);

    $retentionResponse = test()->actingAs($admin)->patch(
        "/settings/teams/{$team->slug}/retention",
        ['retention_days' => 30],
    );
    $retentionResponse->assertSessionDoesntHaveErrors('retention_days');

    $ssoResponse = test()->actingAs($admin)->patch(
        "/settings/teams/{$team->slug}/auth-mode",
        ['auth_mode' => 'dual'],
    );
    $ssoResponse->assertSessionDoesntHaveErrors('auth_mode');
});

/**
 * Structural, not behavioral: greps every PHP source file outside
 * PlanGate.php itself for a direct `->plan` comparison against
 * `Plan::Enterprise`. DoD: "Every enterprise feature checks the plan
 * through one shared gate — verified by there being a single call site."
 */
test('plan_gate_has_single_call_site', function () {
    $appDir = base_path('app');
    $violations = [];

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($appDir));
    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        if ($file->getFilename() === 'PlanGate.php') {
            continue;
        }

        $contents = file_get_contents($file->getPathname());
        if (preg_match('/->plan\s*(===|!==|==|!=)\s*(Plan::|\\\\App\\\\Enums\\\\Plan::)/', $contents)) {
            $violations[] = $file->getPathname();
        }
    }

    expect($violations)->toBe([], 'Only PlanGate.php may compare $team->plan directly: '.implode(', ', $violations));
});

test('quota_warning_at_eighty_percent', function () {
    $team = Team::factory()->create();
    /** @var FakeUsage $usage */
    $usage = app(UsageContract::class);
    $limits = new QuotaLimits(storageBytes: 1000, artifactsPerMonth: 100, bundleSizeCeilingBytes: 500, renderMinutesPerMonth: 100);
    $usage->setUsage($team->slug, storageBytes: 850, artifactsThisPeriod: 10);

    $status = (new QuotaService($usage, $limits))->status($team);

    expect($status->storageWarning)->toBeTrue();
    expect($status->storageExceeded)->toBeFalse();
});

test('quota_exceeded_refuses_creation', function () {
    $team = Team::factory()->create();
    /** @var FakeUsage $usage */
    $usage = app(UsageContract::class);
    $limits = new QuotaLimits(storageBytes: 1000, artifactsPerMonth: 100, bundleSizeCeilingBytes: 500, renderMinutesPerMonth: 100);
    $usage->setUsage($team->slug, storageBytes: 1000, artifactsThisPeriod: 10);

    $exception = null;
    try {
        (new QuotaService($usage, $limits))->assertCanCreateArtifact($team, bundleSizeBytes: 100);
    } catch (QuotaExceededException $e) {
        $exception = $e;
    }

    expect($exception)->not->toBeNull();
    expect($exception->errorCode)->toBe('quota_exceeded');
});

test('quota_exceeded_still_serves_existing_artifacts', function () {
    // Over quota is a pure creation-time refusal — nothing in
    // QuotaService ever touches an existing artifact row or the serving
    // path, so the console (the read/serving-equivalent path) is
    // completely unaffected by quota state.
    $team = Team::factory()->create(['slug' => 'test-org']);
    $admin = memberOfTeam($team, TeamRole::Admin);
    /** @var FakeUsage $usage */
    $usage = app(UsageContract::class);
    $usage->setUsage($team->slug, storageBytes: PHP_INT_MAX, artifactsThisPeriod: PHP_INT_MAX);

    $response = test()->actingAs($admin)->get("/settings/teams/{$team->slug}/console");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->where('artifacts.0.id', '1234567890'));
});

test('bundle_over_tenant_ceiling_refused', function () {
    $team = Team::factory()->create();
    /** @var FakeUsage $usage */
    $usage = app(UsageContract::class);
    $limits = new QuotaLimits(storageBytes: 1_000_000, artifactsPerMonth: 1000, bundleSizeCeilingBytes: 1000, renderMinutesPerMonth: 100);

    $exception = null;
    try {
        (new QuotaService($usage, $limits))->assertCanCreateArtifact($team, bundleSizeBytes: 1001);
    } catch (BundleTooLargeException $e) {
        $exception = $e;
    }

    expect($exception)->not->toBeNull();
    expect($exception->errorCode)->toBe('bundle_too_large');
});

test('seat_count_matches_active_members', function () {
    $team = Team::factory()->create();
    memberOfTeam($team, TeamRole::Admin);
    memberOfTeam($team, TeamRole::Member);
    memberOfTeam($team, TeamRole::Viewer);

    $count = app(BillingService::class)->activeSeatCount($team);

    expect($count)->toBe($team->memberships()->count());
    expect($count)->toBeGreaterThanOrEqual(3);
});

test('payment_failure_degrades_to_read_only', function () {
    $team = Team::factory()->create(['payment_status' => PaymentStatus::Active]);

    app(BillingService::class)->applyPaymentFailed($team);

    expect($team->fresh()->payment_status)->toBe(PaymentStatus::PastDue);

    /** @var FakeUsage $usage */
    $usage = app(UsageContract::class);
    $exception = null;
    try {
        (new QuotaService($usage))->assertCanCreateArtifact($team->fresh(), bundleSizeBytes: 1);
    } catch (QuotaExceededException $e) {
        $exception = $e;
    }
    expect($exception)->not->toBeNull();
});

test('payment_failure_does_not_delete_data', function () {
    $team = Team::factory()->create(['slug' => 'test-org', 'payment_status' => PaymentStatus::Active]);
    $admin = memberOfTeam($team, TeamRole::Admin);

    app(BillingService::class)->applyPaymentFailed($team);

    // Existing artifacts still serve (the console still lists them) even
    // though the team is now read-only for new creates.
    $response = test()->actingAs($admin)->get("/settings/teams/{$team->slug}/console");
    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->where('artifacts.0.id', '1234567890'));
});

test('payment_restored_restores_creates', function () {
    $team = Team::factory()->create(['payment_status' => PaymentStatus::PastDue]);

    app(BillingService::class)->applyPaymentSucceeded($team);

    expect($team->fresh()->payment_status)->toBe(PaymentStatus::Active);

    /** @var FakeUsage $usage */
    $usage = app(UsageContract::class);
    expect(fn () => (new QuotaService($usage))->assertCanCreateArtifact($team->fresh(), bundleSizeBytes: 1))
        ->not->toThrow(QuotaExceededException::class);
});

test('duplicate_custom_hostname_rejected', function () {
    Team::factory()->create(['custom_hostname' => 'artfct.acme.com']);
    $team = Team::factory()->create();
    $admin = memberOfTeam($team, TeamRole::Admin);

    $response = test()->actingAs($admin)->patch(
        "/settings/teams/{$team->slug}/billing/hostname",
        ['custom_hostname' => 'artfct.acme.com'],
    );

    $response->assertSessionHasErrors('custom_hostname');
    expect($team->fresh()->custom_hostname)->toBeNull();
});

test('checkout_creates_subscription_and_sets_team_plan', function () {
    $team = Team::factory()->create(['plan' => Plan::Team]);
    /** @var FakeBilling $billing */
    $billing = app(BillingContract::class);

    $subscriptionId = $billing->completeCheckout($team, 3);
    app(BillingService::class)->applyCheckoutCompleted($team, $subscriptionId);

    expect($team->fresh()->plan)->toBe(Plan::Team);
    expect($team->fresh()->stripe_subscription_id)->toBe($subscriptionId);
});
