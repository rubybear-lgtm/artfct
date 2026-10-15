<?php

use App\Enums\AuditEventType;
use App\Enums\Plan;
use App\Enums\TeamRole;
use App\Models\AuditEvent;
use App\Models\OperatorAuditEvent;
use App\Models\Team;
use App\Models\User;
use App\Services\Governance\SiemExportService;
use App\Services\Operator\OperatorAuditLogger;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => $this->withoutVite());

function trailStaff(string $name = 'Zelda Staffer'): User
{
    $staff = User::factory()->create(['name' => $name]);
    $staff->forceFill(['staff_at' => now()])->save();

    return $staff;
}

function trailView(User $staff, Team $team): ?OperatorAuditEvent
{
    return app(OperatorAuditLogger::class)->recordTenantView($staff, $team, '203.0.113.9', 'pest');
}

test('internal_log_rows_are_append_only', function () {
    $team = Team::factory()->create();
    $staff = trailStaff();
    $row = trailView($staff, $team);

    expect(fn () => $row->update(['outcome' => 'tampered']))->toThrow(LogicException::class);

    $row->outcome = 'tampered';
    expect(fn () => $row->save())->toThrow(LogicException::class);

    expect(fn () => $row->delete())->toThrow(LogicException::class);
    expect(fn () => OperatorAuditEvent::find($row->id)->delete())->toThrow(LogicException::class);

    expect(OperatorAuditEvent::find($row->id)->outcome)->toBe('success');
    expect(OperatorAuditEvent::count())->toBe(1);
});

test('siem_export_includes_reason_key', function () {
    $team = Team::factory()->create(['plan' => Plan::Enterprise]);
    $staff = trailStaff();

    AuditEvent::create([
        'team_id' => $team->id,
        'event_type' => AuditEventType::MemberAdded,
        'actor' => '1',
        'target' => 'user:2',
        'ip' => '127.0.0.1',
        'user_agent' => 'pest',
        'outcome' => 'success',
    ]);
    app(OperatorAuditLogger::class)->record(
        AuditEventType::OperatorPlanChanged,
        $staff,
        $team,
        $team->slug,
        '203.0.113.9',
        'pest',
        'Customer upgraded by invoice, ticket 4411',
    );

    $lines = collect(explode("\n", trim(app(SiemExportService::class)->export($team, actor: 'tester'))))
        ->map(fn (string $line): array => json_decode($line, associative: true, flags: JSON_THROW_ON_ERROR))
        ->keyBy('event_type');

    foreach ($lines as $line) {
        expect($line)->toHaveKey('reason');
    }

    expect($lines['member.added']['reason'])->toBeNull()
        ->and($lines['operator.plan_changed']['reason'])->toBe('Customer upgraded by invoice, ticket 4411')
        ->and($lines['operator.plan_changed']['actor'])->toBe('staff');
});

test('operator_event_has_team_id_and_staff_actor', function () {
    $team = Team::factory()->create();
    $staff = trailStaff();

    trailView($staff, $team);

    $tenantCopy = AuditEvent::query()->where('event_type', AuditEventType::OperatorTenantViewed)->sole();
    expect($tenantCopy->team_id)->toBe($team->id)
        ->and($tenantCopy->actor)->toBe("staff:{$staff->id}")
        ->and($tenantCopy->target)->toBe($team->slug);

    $internal = OperatorAuditEvent::query()->sole();
    expect($internal->team_id)->toBe($team->id)
        ->and($internal->team_slug)->toBe($team->slug)
        ->and($internal->staff_user_id)->toBe($staff->id)
        ->and($internal->suppression_case_id)->toBeNull()
        ->and($internal->ip)->toBe('203.0.113.9');
});

test('tenant_audit_page_labels_staff_without_naming_them', function () {
    $team = Team::factory()->create();
    $admin = memberOfTeam($team, TeamRole::Admin);
    $staff = trailStaff('Zelda Staffer');

    trailView($staff, $team);

    $response = test()->actingAs($admin)->get(route('teams.audit.index', $team))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('events.data', 1)
            ->where('events.data.0.type', 'operator.tenant_viewed')
            ->where('events.data.0.actorName', 'artfct staff')
            ->where('events.data.0.actor', 'staff')
            ->has('members', 1)
            ->where('members.0.id', (string) $admin->id));

    $response->assertDontSee('Zelda Staffer', false)
        ->assertDontSee("staff:{$staff->id}", false);

    test()->actingAs($admin)->get(route('teams.audit.index', [$team, 'actor' => "staff:{$staff->id}"]))
        ->assertInertia(fn (Assert $page) => $page->has('events.data', 1));
});

test('staff_ip_and_user_agent_never_reach_the_tenant', function () {
    $team = Team::factory()->create(['plan' => Plan::Enterprise]);
    $admin = memberOfTeam($team, TeamRole::Admin);
    $staff = trailStaff();

    trailView($staff, $team);

    $tenantCopy = AuditEvent::query()->where('event_type', AuditEventType::OperatorTenantViewed)->sole();
    expect($tenantCopy->ip)->not->toBe('203.0.113.9')
        ->and($tenantCopy->user_agent)->not->toBe('pest');

    // The internal log keeps the real address.
    expect(OperatorAuditEvent::query()->sole()->ip)->toBe('203.0.113.9');

    test()->actingAs($admin)->get(route('teams.audit.index', $team))
        ->assertOk()
        ->assertDontSee('203.0.113.9', false);

    expect(app(SiemExportService::class)->export($team, actor: 'tester'))->not->toContain('203.0.113.9');
});

test('staff_actor_filter_includes_events_with_no_named_staff_user', function () {
    $team = Team::factory()->create();
    $admin = memberOfTeam($team, TeamRole::Admin);

    app(OperatorAuditLogger::class)->record(
        AuditEventType::OperatorPlanChanged,
        null,
        $team,
        $team->slug,
        'internal',
        'artisan',
        'Comped after a billing incident, ticket 77',
    );

    test()->actingAs($admin)->get(route('teams.audit.index', [$team, 'actor' => 'staff']))
        ->assertInertia(fn (Assert $page) => $page->has('events.data', 1));
});

test('tenant_copy_failure_rolls_back_the_internal_row', function () {
    $team = Team::factory()->create();
    $staff = trailStaff();

    Event::listen('eloquent.creating: '.AuditEvent::class, function (): void {
        throw new RuntimeException('tenant copy failed');
    });

    try {
        expect(fn () => trailView($staff, $team))->toThrow(RuntimeException::class);
    } finally {
        Event::forget('eloquent.creating: '.AuditEvent::class);
    }

    expect(OperatorAuditEvent::count())->toBe(0);

    // The view is not stuck behind the dedupe window after the failure.
    expect(trailView($staff, $team))->not->toBeNull();
});

test('tenant_view_audit_event_is_deduped_within_an_hour', function () {
    $team = Team::factory()->create();
    $staff = trailStaff();
    $colleague = trailStaff('Colleague Staffer');

    expect(trailView($staff, $team))->not->toBeNull();

    $this->travel(59)->minutes();
    expect(trailView($staff, $team))->toBeNull();

    expect(AuditEvent::query()->where('event_type', AuditEventType::OperatorTenantViewed)->count())->toBe(1)
        ->and(OperatorAuditEvent::count())->toBe(1);

    // A different staff member is a different viewer, and another tenant is another page.
    expect(trailView($colleague, $team))->not->toBeNull();
    expect(trailView($staff, Team::factory()->create()))->not->toBeNull();

    $this->travel(2)->minutes();
    expect(trailView($staff, $team))->not->toBeNull();

    expect(AuditEvent::query()->where('team_id', $team->id)->where('event_type', AuditEventType::OperatorTenantViewed)->count())->toBe(3);
});

test('mutating_actions_require_a_reason', function () {
    $team = Team::factory()->create();

    expect(fn () => app(OperatorAuditLogger::class)->record(
        AuditEventType::OperatorLimitsChanged,
        trailStaff(),
        $team,
        $team->slug,
        '203.0.113.9',
        'pest',
        '  ',
    ))->toThrow(ValidationException::class);

    expect(OperatorAuditEvent::count())->toBe(0)->and(AuditEvent::count())->toBe(0);
});

test('staff_privileges_are_hidden_from_serialization_and_not_mass_assignable', function () {
    $staff = trailStaff();

    expect($staff->toArray())->not->toHaveKeys(['staff_at', 'case_manager_at'])
        ->and($staff->isStaff())->toBeTrue();

    $user = User::factory()->create();
    $user->fill(['staff_at' => now(), 'case_manager_at' => now()]);

    expect($user->isStaff())->toBeFalse()->and($user->isCaseManager())->toBeFalse();
});
