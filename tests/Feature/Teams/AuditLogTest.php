<?php

use App\Enums\AuditEventType;
use App\Enums\Plan;
use App\Enums\TeamRole;
use App\Models\AuditEvent;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function auditEvent(Team $team, AuditEventType $type, string $actor = 'user:1'): AuditEvent
{
    return AuditEvent::create([
        'team_id' => $team->id,
        'event_type' => $type,
        'actor' => $actor,
        'target' => 'thing',
        'ip' => '127.0.0.1',
        'user_agent' => 'pest',
        'outcome' => 'success',
    ]);
}

test('admins_see_their_teams_audit_events_newest_first', function () {
    $team = Team::factory()->create();
    $admin = memberOfTeam($team, TeamRole::Admin);
    auditEvent($team, AuditEventType::MemberAdded);
    auditEvent($team, AuditEventType::TokenCreated);
    auditEvent(Team::factory()->create(), AuditEventType::TokenRevoked);

    test()->actingAs($admin)->get(route('teams.audit.index', $team))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('teams/audit')
            ->has('events.data', 2)
            ->where('events.data.0.type', 'token.created'));
});

test('the_audit_log_filters_by_event_type', function () {
    $team = Team::factory()->create();
    $admin = memberOfTeam($team, TeamRole::Admin);
    auditEvent($team, AuditEventType::MemberAdded);
    auditEvent($team, AuditEventType::TokenCreated);

    test()->actingAs($admin)->get(route('teams.audit.index', [$team, 'type' => 'member.added']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('events.data', 1)
            ->where('filters.type', 'member.added'));
});

test('members_cannot_read_the_audit_log', function () {
    $team = Team::factory()->create();
    $member = memberOfTeam($team, TeamRole::Member);

    test()->actingAs($member)->get(route('teams.audit.index', $team))->assertForbidden();
});

test('outsiders_get_a_404_for_the_audit_log', function () {
    $team = Team::factory()->create();

    test()->actingAs(User::factory()->create())->get(route('teams.audit.index', $team))->assertNotFound();
});

test('actor_ids_resolve_to_names_and_deleted_users_render', function () {
    $team = Team::factory()->create();
    $admin = memberOfTeam($team, TeamRole::Admin);
    auditEvent($team, AuditEventType::MemberAdded, (string) $admin->id);
    auditEvent($team, AuditEventType::MemberRemoved, '999999');
    auditEvent($team, AuditEventType::SearchPerformed, 'slack:unfurl');

    test()->actingAs($admin)->get(route('teams.audit.index', $team))
        ->assertInertia(fn (Assert $page) => $page
            ->where('events.data.2.actorName', $admin->name)
            ->where('events.data.1.actorName', 'deleted user')
            ->where('events.data.0.actorName', 'slack:unfurl'));
});

test('the_audit_log_filters_by_actor_and_date', function () {
    $team = Team::factory()->create();
    $admin = memberOfTeam($team, TeamRole::Admin);
    auditEvent($team, AuditEventType::MemberAdded, 'user-a');
    auditEvent($team, AuditEventType::MemberAdded, 'user-b');

    test()->actingAs($admin)->get(route('teams.audit.index', [$team, 'actor' => 'user-a']))
        ->assertInertia(fn (Assert $page) => $page->has('events.data', 1));
    test()->actingAs($admin)->get(route('teams.audit.index', [$team, 'from' => now()->addDay()->toDateString()]))
        ->assertInertia(fn (Assert $page) => $page->has('events.data', 0));
});

test('enterprise_teams_can_export_and_the_export_is_audited', function () {
    $team = Team::factory()->create(['plan' => Plan::Enterprise]);
    $admin = memberOfTeam($team, TeamRole::Admin);
    auditEvent($team, AuditEventType::MemberAdded);

    $response = test()->actingAs($admin)->get(route('teams.audit.export', $team))->assertOk();

    expect($response->streamedContent())->toContain('member.added');
    expect(AuditEvent::query()->where('team_id', $team->id)->where('event_type', AuditEventType::ExportPerformed)->exists())->toBeTrue();
});

test('team_plan_export_is_refused', function () {
    $team = Team::factory()->create(['plan' => Plan::Team]);
    $admin = memberOfTeam($team, TeamRole::Admin);

    test()->actingAs($admin)->get(route('teams.audit.export', $team))->assertForbidden();
});
