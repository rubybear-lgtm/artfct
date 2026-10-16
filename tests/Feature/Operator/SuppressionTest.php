<?php

use App\Enums\AuditEventType;
use App\Enums\Plan;
use App\Enums\TeamRole;
use App\Models\AuditEvent;
use App\Models\OperatorAuditEvent;
use App\Models\OperatorSuppressionCase;
use App\Models\Team;
use App\Models\User;
use App\Services\Governance\SiemExportService;
use App\Services\Operator\OperatorAuditLogger;
use App\Services\Operator\SuppressionCaseService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => $this->withoutVite());

function caseStaff(bool $caseManager = false, string $name = 'Case Staffer'): User
{
    $staff = User::factory()->create(['name' => $name]);
    $staff->forceFill([
        'staff_at' => now(),
        'case_manager_at' => $caseManager ? now() : null,
    ])->save();

    return $staff;
}

function caseOpen(Team $team, ?User $manager = null, ?DateTimeInterface $expiresAt = null): OperatorSuppressionCase
{
    return app(SuppressionCaseService::class)->create(
        $manager ?? caseStaff(true, 'Case Manager'),
        $team,
        'legal_request',
        'LEGAL-2041',
        Carbon::instance($expiresAt ?? now()->addDays(30)),
    );
}

function caseView(User $staff, Team $team): ?OperatorAuditEvent
{
    return app(OperatorAuditLogger::class)->recordTenantView($staff, $team, '203.0.113.9', 'pest');
}

test('suppressed_view_is_absent_from_index_picker_and_export', function () {
    $team = Team::factory()->create(['plan' => Plan::Enterprise]);
    $admin = memberOfTeam($team, TeamRole::Admin);
    AuditEvent::create([
        'team_id' => $team->id,
        'event_type' => AuditEventType::TokenCreated,
        'actor' => (string) $admin->id,
        'target' => 'token',
        'ip' => '127.0.0.1',
        'user_agent' => 'pest',
        'outcome' => 'success',
    ]);
    caseOpen($team);

    caseView(caseStaff(), $team);

    expect(AuditEvent::query()->where('event_type', AuditEventType::OperatorTenantViewed)->exists())->toBeFalse();

    test()->actingAs($admin)->get(route('teams.audit.index', $team))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('events.data', 1)
            ->where('events.data.0.type', 'token.created')
            ->where('total', 1)
            ->has('members', 1));

    $export = app(SiemExportService::class)->export($team, actor: (string) $admin->id);
    expect($export)->not->toContain('operator.tenant_viewed')->not->toContain('staff');
});

test('suppressed_view_is_present_in_the_internal_log', function () {
    $team = Team::factory()->create();
    $staff = caseStaff();
    $case = caseOpen($team);

    $row = caseView($staff, $team);

    expect($row)->not->toBeNull()
        ->and($row->suppression_case_id)->toBe($case->id)
        ->and($row->team_id)->toBe($team->id)
        ->and($row->team_slug)->toBe($team->slug)
        ->and($row->staff_user_id)->toBe($staff->id)
        ->and($row->event_type)->toBe(AuditEventType::OperatorTenantViewed);

    // A suppressed view still counts toward the hourly dedupe.
    expect(caseView($staff, $team))->toBeNull();
    expect(OperatorAuditEvent::query()->where('event_type', AuditEventType::OperatorTenantViewed)->count())->toBe(1);
});

test('staff_without_case_manager_cannot_create_a_case', function () {
    $team = Team::factory()->create();
    $staff = caseStaff(caseManager: false);
    $service = app(SuppressionCaseService::class);

    expect(fn () => $service->create($staff, $team, 'legal_request', 'LEGAL-1', now()->addDays(10)))
        ->toThrow(AuthorizationException::class);

    expect(OperatorSuppressionCase::count())->toBe(0);

    $denied = OperatorAuditEvent::query()->where('event_type', AuditEventType::OperatorCaseDenied)->sole();
    expect($denied->outcome)->toBe('denied')
        ->and($denied->staff_user_id)->toBe($staff->id)
        ->and($denied->team_id)->toBe($team->id);

    // The same refusal holds for renewing and releasing an existing case.
    $case = caseOpen($team);
    $expiresAt = $case->expires_at;

    expect(fn () => $service->renew($staff, $case, 'LEGAL-2', now()->addDays(60)))
        ->toThrow(AuthorizationException::class);
    expect(fn () => $service->release($staff, $case))
        ->toThrow(AuthorizationException::class);

    $case->refresh();
    expect($case->reference)->toBe('LEGAL-2041')
        ->and($case->expires_at->equalTo($expiresAt))->toBeTrue()
        ->and($case->released_at)->toBeNull()
        ->and(OperatorAuditEvent::query()->where('event_type', AuditEventType::OperatorCaseDenied)->count())->toBe(3)
        ->and(AuditEvent::count())->toBe(0);
});

test('case_requires_category_reference_and_expiry_within_90_days', function (?string $category, ?string $reference, ?int $days, string $errorKey) {
    $this->freezeTime();
    $team = Team::factory()->create();
    $manager = caseStaff(true);

    $expiresAt = $days === null ? null : now()->addDays($days);

    expect(fn () => app(SuppressionCaseService::class)->create($manager, $team, $category, $reference, $expiresAt))
        ->toThrow(ValidationException::class);

    try {
        app(SuppressionCaseService::class)->create($manager, $team, $category, $reference, $expiresAt);
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($errorKey);
    }

    expect(OperatorSuppressionCase::count())->toBe(0)
        ->and(OperatorAuditEvent::count())->toBe(0);
})->with([
    'no category' => [null, 'LEGAL-1', 10, 'category'],
    'unknown category' => ['curiosity', 'LEGAL-1', 10, 'category'],
    'no reference' => ['legal_request', null, 10, 'reference'],
    'blank reference' => ['legal_request', '   ', 10, 'reference'],
    'no expiry' => ['legal_request', 'LEGAL-1', null, 'expires_at'],
    'expiry in the past' => ['legal_request', 'LEGAL-1', -1, 'expires_at'],
    'expiry beyond 90 days' => ['abuse_investigation', 'LEGAL-1', 91, 'expires_at'],
]);

test('case_may_run_for_exactly_90_days_and_renewal_is_bounded_too', function () {
    $this->freezeTime();
    $team = Team::factory()->create();
    $manager = caseStaff(true);
    $service = app(SuppressionCaseService::class);

    $case = $service->create($manager, $team, 'abuse_investigation', 'ABUSE-7', now()->addDays(90));
    expect($case->expires_at->toDateTimeString())->toBe(now()->addDays(90)->toDateTimeString());

    expect(fn () => $service->renew($manager, $case, 'ABUSE-7', now()->addDays(90)))
        ->toThrow(ValidationException::class);
    expect(fn () => $service->renew($manager, $case, 'ABUSE-8', now()->addDays(91)))
        ->toThrow(ValidationException::class);

    $this->travel(30)->days();
    $renewed = $service->renew($manager, $case, 'ABUSE-8', now()->addDays(90));

    expect($renewed->reference)->toBe('ABUSE-8')
        ->and($renewed->expires_at->toDateTimeString())->toBe(now()->addDays(90)->toDateTimeString());
});

test('mutating_actions_stay_visible_during_a_case', function () {
    $team = Team::factory()->create();
    $admin = memberOfTeam($team, TeamRole::Admin);
    $staff = caseStaff();
    $case = caseOpen($team);

    $internal = app(OperatorAuditLogger::class)->record(
        AuditEventType::OperatorPlanChanged,
        $staff,
        $team,
        $team->slug,
        '203.0.113.9',
        'pest',
        'Moved to enterprise per signed order ORD-77',
    );

    expect($internal->suppression_case_id)->toBeNull();

    $tenantCopy = AuditEvent::query()->where('event_type', AuditEventType::OperatorPlanChanged)->sole();
    expect($tenantCopy->team_id)->toBe($team->id)
        ->and($tenantCopy->actor)->toBe("staff:{$staff->id}")
        ->and($tenantCopy->reason)->toBe('Moved to enterprise per signed order ORD-77')
        ->and($tenantCopy->created_at->diffInMinutes(now(), absolute: true))->toBeLessThan(1);

    test()->actingAs($admin)->get(route('teams.audit.index', $team))
        ->assertInertia(fn (Assert $page) => $page
            ->has('events.data', 1)
            ->where('events.data.0.type', 'operator.plan_changed')
            ->where('events.data.0.actorName', 'artfct staff')
            ->where('events.data.0.reason', 'Moved to enterprise per signed order ORD-77'));

    expect($case->refresh()->isActive())->toBeTrue();
});

test('case_changes_are_logged_internally_and_never_to_the_tenant', function () {
    $team = Team::factory()->create();
    $manager = caseStaff(true, 'Case Manager');
    $service = app(SuppressionCaseService::class);

    $case = $service->create($manager, $team, 'legal_request', 'LEGAL-1', now()->addDays(10));
    $service->renew($manager, $case, 'LEGAL-2', now()->addDays(40));
    $service->release($manager, $case);

    $types = OperatorAuditEvent::query()->where('team_id', $team->id)->orderBy('id')->pluck('event_type')->all();
    expect($types)->toBe([
        AuditEventType::OperatorCaseCreated,
        AuditEventType::OperatorCaseRenewed,
        AuditEventType::OperatorCaseReleased,
    ]);

    foreach (OperatorAuditEvent::all() as $row) {
        expect($row->staff_user_id)->toBe($manager->id)
            ->and($row->suppression_case_id)->toBeNull()
            ->and($row->target)->toBe("case:{$case->id}");
    }

    expect(AuditEvent::query()->where('team_id', $team->id)->count())->toBe(0)
        ->and(AuditEvent::query()->where('event_type', 'like', 'operator.case_%')->count())->toBe(0);
});

test('expiry_releases_withheld_events_with_original_timestamps', function () {
    $this->freezeTime();
    $team = Team::factory()->create();
    $admin = memberOfTeam($team, TeamRole::Admin);
    $first = caseStaff();
    $second = caseStaff(false, 'Second Staffer');
    $viewedAt = now()->startOfSecond();

    $case = caseOpen($team, expiresAt: now()->addDays(2));

    caseView($first, $team);
    $this->travel(10)->minutes();
    caseView($second, $team);
    $secondViewedAt = now()->startOfSecond();

    expect(AuditEvent::count())->toBe(0);

    $this->artisan('ops:release-suppressed')->assertSuccessful();
    expect(AuditEvent::count())->toBe(0);

    $this->travel(3)->days();
    $this->artisan('ops:release-suppressed')->assertSuccessful();

    $events = AuditEvent::query()->where('team_id', $team->id)->orderBy('created_at')->get();
    expect($events)->toHaveCount(2)
        ->and($events[0]->event_type)->toBe(AuditEventType::OperatorTenantViewed)
        ->and($events[0]->actor)->toBe("staff:{$first->id}")
        ->and($events[0]->created_at->toDateTimeString())->toBe($viewedAt->toDateTimeString())
        ->and($events[1]->actor)->toBe("staff:{$second->id}")
        ->and($events[1]->created_at->toDateTimeString())->toBe($secondViewedAt->toDateTimeString());

    expect($case->refresh()->released_at)->not->toBeNull();

    test()->actingAs($admin)->get(route('teams.audit.index', $team))
        ->assertInertia(fn (Assert $page) => $page
            ->has('events.data', 2)
            ->where('events.data.0.actorName', 'artfct staff'));
});

test('manual_release_publishes_withheld_events_straight_away', function () {
    $team = Team::factory()->create();
    $manager = caseStaff(true);
    $case = caseOpen($team, $manager);

    caseView(caseStaff(), $team);
    expect(AuditEvent::count())->toBe(0);

    app(SuppressionCaseService::class)->release($manager, $case);

    expect(AuditEvent::query()->where('event_type', AuditEventType::OperatorTenantViewed)->count())->toBe(1);

    // After release the tenant copy is written as normal again.
    caseView(caseStaff(false, 'Another Staffer'), $team);
    expect(AuditEvent::query()->where('event_type', AuditEventType::OperatorTenantViewed)->count())->toBe(2);
});

test('release_command_is_idempotent', function () {
    $team = Team::factory()->create();
    $case = caseOpen($team, expiresAt: now()->addDay());
    caseView(caseStaff(), $team);

    $this->travel(2)->days();

    $this->artisan('ops:release-suppressed')->assertSuccessful();
    expect(AuditEvent::count())->toBe(1);
    $internalRows = OperatorAuditEvent::count();

    $this->artisan('ops:release-suppressed')->assertSuccessful();
    $this->artisan('ops:release-suppressed')->assertSuccessful();

    expect(AuditEvent::count())->toBe(1)
        ->and(OperatorAuditEvent::count())->toBe($internalRows)
        ->and($case->refresh()->released_at)->not->toBeNull();
});

test('tenant_page_shows_no_marker_for_withheld_events', function () {
    $team = Team::factory()->create();
    $admin = memberOfTeam($team, TeamRole::Admin);

    $props = fn (): array => test()->actingAs($admin)
        ->get(route('teams.audit.index', $team))
        ->assertOk()
        ->viewData('page')['props'];

    $before = $props();

    caseOpen($team);
    $viewer = caseStaff();
    caseView($viewer, $team);
    $this->travel(2)->hours();
    caseView($viewer, $team);

    $after = $props();

    expect($after)->toEqual($before)
        ->and($after['total'])->toBe(0)
        ->and($after['events']['data'])->toBe([]);

    test()->actingAs($admin)->get(route('teams.audit.index', $team))
        ->assertInertia(fn (Assert $page) => $page
            ->has('events.data', 0)
            ->where('total', 0));
});
