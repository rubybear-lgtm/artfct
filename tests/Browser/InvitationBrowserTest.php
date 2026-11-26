<?php

use App\Enums\Plan;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('an_invitation_opened_by_the_wrong_account_cannot_be_accepted', function () {
    $team = Team::factory()->create(['name' => 'Invited Team']);
    $admin = memberOfTeam($team, TeamRole::Admin);
    $otherUser = User::factory()->create(['email' => 'other@example.com']);
    $invitation = $team->invitations()->create([
        'email' => 'invited@example.com',
        'role' => TeamRole::Member,
        'invited_by' => $admin->id,
        'expires_at' => now()->addDay(),
    ]);
    test()->actingAs($otherUser);

    visit(route('invitations.show', $invitation))
        ->assertSee('other@example.com')
        ->assertSee('invited@example.com')
        ->assertSee('Sign out')
        ->assertDontSee('Accept invitation')
        ->assertNoAccessibilityIssues()
        ->assertNoJavaScriptErrors();

    expect($team->memberships()->where('user_id', $otherUser->id)->exists())->toBeFalse()
        ->and($invitation->fresh()->accepted_at)->toBeNull();
});

test('an_invitee_can_decline_without_joining_the_team', function () {
    $team = Team::factory()->create(['name' => 'Declined Team', 'plan' => Plan::Team]);
    $admin = memberOfTeam($team, TeamRole::Admin);
    $invitee = User::factory()->create(['email' => 'decliner@example.com']);
    $invitation = $team->invitations()->create([
        'email' => $invitee->email,
        'role' => TeamRole::Member,
        'invited_by' => $admin->id,
        'expires_at' => now()->addDay(),
    ]);
    test()->actingAs($invitee);

    visit(route('invitations.show', $invitation))
        ->assertSee('Join Declined Team')
        ->click('Decline')
        ->assertSee('Invitation declined')
        ->assertNoJavaScriptErrors();

    expect($team->invitations()->whereKey($invitation->id)->exists())->toBeFalse()
        ->and($team->memberships()->where('user_id', $invitee->id)->exists())->toBeFalse();
});

test('an_expired_invitation_explains_how_to_get_a_new_one', function () {
    $team = Team::factory()->create(['name' => 'Northwind Analytics']);
    $admin = memberOfTeam($team, TeamRole::Admin);
    $invitation = $team->invitations()->create([
        'email' => 'invitee@example.com',
        'role' => TeamRole::Member,
        'invited_by' => $admin->id,
        'expires_at' => now()->subDay(),
    ]);

    visit(route('invitations.show', $invitation))
        ->assertSee('This invitation has expired')
        ->assertSee('to send a new one')
        ->assertDontSee('Create an account and join')
        ->assertNoAccessibilityIssues()
        ->assertNoJavaScriptErrors();

    expect($team->memberships()->count())->toBe(1);
});

test('invitee_accepts_from_the_email_link_and_lands_in_the_team', function () {
    $team = Team::factory()->create([
        'name' => 'Northwind Analytics',
        'plan' => Plan::Team,
    ]);
    $admin = memberOfTeam($team, TeamRole::Admin);
    $invitation = $team->invitations()->create([
        'email' => 'invitee@example.com',
        'role' => TeamRole::Member,
        'invited_by' => $admin->id,
        'expires_at' => now()->addDays(3),
    ]);

    $page = visit(route('invitations.show', $invitation));

    $page->assertNoJavaScriptErrors()
        ->assertSee('Join Northwind Analytics')
        ->click('Create an account and join')
        ->assertValue('email', 'invitee@example.com')
        ->fill('name', 'Invitee')
        ->click('Continue with Google')
        ->assertSee('Welcome to Northwind Analytics')
        ->assertNoJavaScriptErrors();

    expect($team->memberships()->whereHas('user', fn ($query) => $query->where('email', 'invitee@example.com'))->exists())->toBeTrue();
});
