<?php

use App\Enums\Plan;
use App\Enums\TeamRole;
use App\Models\McpConnection;
use App\Models\Team;
use App\Models\TeamInvitation;
use Inertia\Testing\AssertableInertia as Assert;

test('admin_overview_summarises_people_plan_tools_and_records', function () {
    $team = Team::factory()->create(['plan' => Plan::Team]);
    $admin = memberOfTeam($team, TeamRole::Admin);
    memberOfTeam($team, TeamRole::Admin);
    memberOfTeam($team, TeamRole::Member);

    TeamInvitation::factory()->create(['team_id' => $team->id, 'accepted_at' => null]);
    TeamInvitation::factory()->create(['team_id' => $team->id, 'accepted_at' => now()]);

    McpConnection::factory()->count(2)->create(['team_id' => $team->id]);
    McpConnection::factory()->revoked()->create(['team_id' => $team->id]);
    McpConnection::factory()->expired()->create(['team_id' => $team->id]);

    test()->actingAs($admin)->get(route('teams.admin.show', $team))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('teams/admin')
            ->where('team.slug', $team->slug)
            ->where('team.name', $team->name)
            ->where('team.plan', 'team')
            ->where('team.isEnterprise', false)
            ->where('members.total', 3)
            ->where('members.admins', 2)
            ->where('pendingInvitations', 1)
            ->where('connections.active', 2));
});

test('admin_overview_flags_an_enterprise_plan', function () {
    $team = Team::factory()->create(['plan' => Plan::Enterprise]);
    $admin = memberOfTeam($team, TeamRole::Admin);

    test()->actingAs($admin)->get(route('teams.admin.show', $team))
        ->assertInertia(fn (Assert $page) => $page
            ->where('team.plan', 'enterprise')
            ->where('team.isEnterprise', true));
});
