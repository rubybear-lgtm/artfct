<?php

use App\Actions\Teams\CreateTeam;
use App\Enums\PaymentStatus;
use App\Enums\Plan;
use App\Enums\TeamRole;
use App\Models\OrgToken;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('billing_page_shows_plan_seats_and_limits_to_admins', function () {
    $team = Team::factory()->create(['plan' => Plan::Free]);
    $admin = memberOfTeam($team, TeamRole::Admin);

    test()->actingAs($admin)->get(route('teams.billing.show', $team))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('teams/billing')
            ->where('team.plan', 'free')
            ->where('canManage', true)
            ->has('limits.free.storage_bytes')
            ->has('limits.team.storage_bytes'));
});

test('billing_page_tells_members_they_cannot_manage', function () {
    $team = Team::factory()->create();
    $member = memberOfTeam($team, TeamRole::Member);

    test()->actingAs($member)->get(route('teams.billing.show', $team))
        ->assertInertia(fn (Assert $page) => $page->where('canManage', false));
});

test('billing_page_is_hidden_from_non_members', function () {
    $team = Team::factory()->create();
    $stranger = User::factory()->create();

    test()->actingAs($stranger)->get(route('teams.billing.show', $team))->assertNotFound();
});

test('shared_props_expose_past_due_state_for_the_banner', function () {
    $team = Team::factory()->create(['payment_status' => PaymentStatus::PastDue]);
    $admin = memberOfTeam($team, TeamRole::Admin);
    $admin->switchTeam($team);

    test()->actingAs($admin)->get(route('teams.billing.show', $team))
        ->assertInertia(fn (Assert $page) => $page->where('currentTeam.paymentStatus', 'past_due')->where('currentTeam.slug', $team->slug));
});

test('only_the_owner_can_cancel_the_subscription', function () {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Owned Co');
    $team->forceFill(['plan' => Plan::Team, 'stripe_subscription_id' => 'sub_1'])->save();
    $admin = memberOfTeam($team, TeamRole::Admin);

    test()->actingAs($admin)->post(route('teams.billing.cancel', $team))->assertForbidden();
});

test('tokens_page_lists_tokens_without_values_and_gates_creation', function () {
    $team = Team::factory()->create();
    $admin = memberOfTeam($team, TeamRole::Admin);
    $viewer = memberOfTeam($team, TeamRole::Viewer);
    OrgToken::factory()->create(['team_id' => $team->id, 'user_id' => $admin->id, 'name' => 'ci', 'last_four' => 'ab12']);

    test()->actingAs($admin)->get(route('teams.tokens.index', $team))
        ->assertInertia(fn (Assert $page) => $page
            ->component('teams/tokens')
            ->where('canCreate', true)
            ->where('tokens.0.lastFour', 'ab12')
            ->missing('tokens.0.jti'));

    test()->actingAs($viewer)->get(route('teams.tokens.index', $team))
        ->assertInertia(fn (Assert $page) => $page->where('canCreate', false));
});

test('team_edit_page_carries_owner_and_viewer_permissions', function () {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Captain Co');
    $admin = memberOfTeam($team, TeamRole::Admin);

    test()->actingAs($owner)->get(route('teams.edit', $team))
        ->assertInertia(fn (Assert $page) => $page->where('viewer.isOwner', true)->where('viewer.canDelete', true)->has('invitations'));

    test()->actingAs($admin)->get(route('teams.edit', $team))
        ->assertInertia(fn (Assert $page) => $page->where('viewer.isOwner', false)->where('viewer.canDelete', false)->where('viewer.canTransfer', false));
});
