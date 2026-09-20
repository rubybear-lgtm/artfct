<?php

use App\Enums\TeamRole;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('invitee_signs_in_from_the_email_link_and_accepts', function () {
    $team = Team::factory()->create(['name' => 'Northwind Analytics']);
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
        ->click('Sign in to accept')
        ->fill('email', 'invitee@example.com')
        ->fill('name', 'Invitee')
        ->click('Continue with Google')
        ->assertSee('Join Northwind Analytics')
        ->click('Accept')
        ->assertNoJavaScriptErrors();

    expect($team->memberships()->whereHas('user', fn ($query) => $query->where('email', 'invitee@example.com'))->exists())->toBeTrue();
});
