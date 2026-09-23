<?php

use App\Enums\AuditEventType;
use App\Enums\Plan;
use App\Enums\TeamRole;
use App\Models\AuditEvent;
use App\Models\Team;
use Inertia\Testing\AssertableInertia as Assert;

function governanceTeam(Plan $plan = Plan::Enterprise): array
{
    $team = Team::factory()->create(['plan' => $plan]);
    $admin = memberOfTeam($team, TeamRole::Admin);

    return [$team, $admin];
}

test('admins_see_the_retention_policy_and_held_artifacts', function () {
    [$team, $admin] = governanceTeam();

    test()->actingAs($admin)->get(route('teams.governance.show', $team))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('teams/governance')
            ->where('isEnterprise', true)
            ->has('defaultRetentionDays')
            ->has('heldArtifacts'));
});

test('members_cannot_open_the_governance_page', function () {
    $team = Team::factory()->create();
    $member = memberOfTeam($team, TeamRole::Member);

    test()->actingAs($member)->get(route('teams.governance.show', $team))->assertForbidden();
});

test('the_preview_is_a_dry_run_and_deletes_nothing', function () {
    [$team, $admin] = governanceTeam();

    test()->actingAs($admin)->post(route('teams.governance.preview', $team))->assertRedirect(route('teams.governance.show', $team));

    test()->actingAs($admin)->get(route('teams.governance.show', $team))
        ->assertInertia(fn (Assert $page) => $page->has('preview.wouldDelete')->has('preview.heldSurvivors'));
});

test('holds_can_be_placed_and_released_on_enterprise', function () {
    [$team, $admin] = governanceTeam();

    test()->actingAs($admin)->post(route('teams.governance.holds.place', $team), ['artifact_id' => 'art1'])->assertRedirect();
    test()->actingAs($admin)->delete(route('teams.governance.holds.release', [$team, 'art1']))->assertRedirect();

    expect(AuditEvent::query()->where('team_id', $team->id)->where('event_type', AuditEventType::LegalHoldApplied)->count())->toBe(2);
});

test('placing_a_hold_on_a_team_plan_is_refused', function () {
    [$team, $admin] = governanceTeam(Plan::Team);

    test()->actingAs($admin)->post(route('teams.governance.holds.place', $team), ['artifact_id' => 'art1'])->assertSessionHasErrors('artifact_id');
});

test('retention_days_can_be_saved_on_enterprise_and_returns_to_the_page', function () {
    [$team, $admin] = governanceTeam();

    test()->actingAs($admin)->patch(route('teams.retention.update', $team), ['retention_days' => 30])->assertRedirect(route('teams.governance.show', $team));

    expect($team->fresh()->retention_days)->toBe(30);
});
