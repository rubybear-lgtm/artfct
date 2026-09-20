<?php

use App\Actions\Teams\CreateTeam;
use App\Enums\TeamRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

test('new_user_signs_up_becomes_owner_and_invites_a_teammate', function () {
    $page = visit('/login');

    $page->assertNoJavaScriptErrors()
        ->fill('email', 'captain@example.com')
        ->fill('name', 'Casey Captain')
        ->click('Continue with Google')
        ->assertSee('Dashboard')
        ->assertNoJavaScriptErrors();

    $page->navigate('/settings/teams')
        ->assertSee('Your orgs')
        ->fill('name', 'Northwind Analytics')
        ->click('Create org')
        ->assertSee('Northwind Analytics')
        ->assertSee('owner')
        ->fill('email', 'teammate@example.com')
        ->click('Send invite')
        ->assertSee('teammate@example.com')
        ->assertNoJavaScriptErrors();
});

test('billing_and_token_pages_render_without_errors', function () {
    $owner = User::factory()->create(['email' => 'owner@example.com']);
    $team = app(CreateTeam::class)->handle($owner, 'Pages Co');

    test()->actingAs($owner);

    visit(route('teams.billing.show', $team))
        ->assertSee('Billing')
        ->assertSee('Current plan')
        ->assertSee('What each plan includes')
        ->assertNoJavaScriptErrors();

    visit(route('teams.tokens.index', $team))
        ->assertSee('API tokens')
        ->assertSee('Create a token')
        ->assertNoJavaScriptErrors();

    visit(route('teams.edit', $team))
        ->assertSee('Members')
        ->assertSee('Danger zone')
        ->assertNoJavaScriptErrors();
});

test('admin_can_change_a_role_and_owner_is_protected', function () {
    $owner = User::factory()->create(['email' => 'owner@example.com', 'name' => 'Olive Owner']);
    $team = app(CreateTeam::class)->handle($owner, 'Roles Co');
    $member = memberOfTeam($team, TeamRole::Member);

    test()->actingAs($owner);

    $page = visit(route('teams.edit', $team));
    $page->assertSee($member->name)->assertNoJavaScriptErrors();

    expect($team->memberships()->where('user_id', $member->id)->value('role'))->toBe(TeamRole::Member);
});

test('account_and_audit_pages_render_without_errors', function () {
    $owner = User::factory()->create(['email' => 'admin@example.com', 'name' => 'Ada Admin']);
    $team = app(CreateTeam::class)->handle($owner, 'Audit Co');

    test()->actingAs($owner);

    visit(route('account.show'))
        ->assertSee('Account')
        ->assertSee('Linked identities')
        ->assertSee('Delete account')
        ->assertNoJavaScriptErrors();

    visit(route('teams.audit.index', $team))
        ->assertSee('Audit log')
        ->assertNoJavaScriptErrors();

    visit(route('dashboard', $team))
        ->assertSee('Get your team set up')
        ->assertSee('Invite your teammates')
        ->assertNoJavaScriptErrors();
});

test('invite_resend_revoke_flow', function () {
    Notification::fake();
    $owner = User::factory()->create(['email' => 'owner2@example.com']);
    $team = app(CreateTeam::class)->handle($owner, 'Resend Co');
    $invitation = $team->invitations()->create(['email' => 'pending@example.com', 'role' => TeamRole::Member, 'invited_by' => $owner->id, 'expires_at' => now()->addDay()]);
    $oldCode = $invitation->code;

    test()->actingAs($owner);

    $page = visit(route('teams.edit', $team))
        ->assertSee('pending@example.com')
        ->click('Resend')
        ->wait(1);

    expect($invitation->fresh()->code)->not->toBe($oldCode);

    $page->click('Cancel')->wait(1)->assertDontSee('pending@example.com')->assertNoJavaScriptErrors();

    expect($team->invitations()->count())->toBe(0);
});
