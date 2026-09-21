<?php

use App\Actions\Teams\CreateTeam;
use App\Enums\TeamRole;
use App\Models\McpConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('team members can inspect setup details and revoke an MCP connection', function () {
    $owner = User::factory()->create(['name' => 'MCP Owner']);
    $team = app(CreateTeam::class)->handle($owner, 'MCP Browser Co');
    $connection = McpConnection::factory()->create([
        'team_id' => $team->id,
        'user_id' => $owner->id,
        'name' => 'Browser agent',
        'scopes' => ['artifacts:read'],
    ]);

    test()->actingAs($owner);

    $page = visit(route('teams.mcp-connections.index', $team))
        ->assertSee('Connect an agent')
        ->assertSee('OAuth discovery:')
        ->assertSee('artifacts:read')
        ->assertSee('Browser agent')
        ->assertNoJavaScriptErrors();

    $page->click('Revoke')
        ->assertSee('Confirm revoke?')
        ->click('Confirm revoke?')
        ->wait(1)
        ->assertSee('revoked')
        ->assertNoJavaScriptErrors();

    expect($connection->fresh()->revoked_at)->not->toBeNull();
});

test('the MCP connections page is available to team members without exposing credentials', function () {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'MCP Member Co');
    $member = User::factory()->create();
    $team->memberships()->create(['user_id' => $member->id, 'role' => TeamRole::Member]);
    McpConnection::factory()->create([
        'team_id' => $team->id,
        'user_id' => $owner->id,
        'name' => 'Owner agent',
    ]);

    test()->actingAs($member);

    visit(route('teams.mcp-connections.index', $team))
        ->assertSee('Owner agent')
        ->assertDontSee('access_token')
        ->assertDontSee('refresh_token')
        ->assertNoJavaScriptErrors();
});

test('expired connections offer a direct reconnect command', function () {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'MCP Recovery Co');
    McpConnection::factory()->create([
        'team_id' => $team->id,
        'user_id' => $owner->id,
        'name' => 'Expired browser agent',
        'expires_at' => now()->subMinute(),
    ]);

    test()->actingAs($owner);

    visit(route('teams.mcp-connections.index', $team))
        ->assertSee('Expired browser agent')
        ->assertSee('expired')
        ->assertSee('Reconnect')
        ->assertNoJavaScriptErrors();
});
