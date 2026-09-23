<?php

use App\Actions\Teams\CreateTeam;
use App\Enums\TeamRole;
use App\Models\McpConnection;
use App\Models\OAuthRefreshToken;
use App\Models\OrgToken;
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

test('team members can start a new connection through the page', function () {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'MCP Create Co');

    test()->actingAs($owner);

    $page = visit(route('teams.mcp-connections.index', $team))
        ->assertSee('Start a connection')
        ->assertNoJavaScriptErrors();

    $page->fill('client_name', 'Browser-created agent')
        ->check('scopes', 'artifacts:deploy')
        ->click('Start connection')
        ->wait(1)
        ->assertSee('Browser-created agent')
        ->assertNoJavaScriptErrors();

    $connection = McpConnection::query()->where('team_id', $team->id)->sole();
    expect($connection->user_id)->toBe($owner->id)
        ->and($connection->client_name)->toBe('Browser-created agent')
        ->and($connection->scopes)->toBe(['artifacts:read', 'artifacts:deploy', 'collections:read', 'usage:read']);
});

test('an active connection can be forced through reauthorization', function () {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'MCP Reauth Co');
    $connection = McpConnection::factory()->create([
        'team_id' => $team->id,
        'user_id' => $owner->id,
        'name' => 'Reauthorizable browser agent',
    ]);
    $orgToken = OrgToken::factory()->create([
        'team_id' => $team->id,
        'user_id' => $owner->id,
        'mcp_connection_id' => $connection->id,
    ]);
    $refreshToken = OAuthRefreshToken::factory()->create([
        'team_id' => $team->id,
        'user_id' => $owner->id,
        'mcp_connection_id' => $connection->id,
    ]);

    test()->actingAs($owner);

    $page = visit(route('teams.mcp-connections.index', $team))
        ->assertSee('Reauthorizable browser agent')
        ->assertSee('Reauthorize')
        ->assertNoJavaScriptErrors();

    $page->click('Reauthorize')
        ->assertSee('Confirm reauthorize?')
        ->click('Confirm reauthorize?')
        ->wait(1)
        ->assertSee('Reauthorization required')
        ->assertNoJavaScriptErrors();

    expect($orgToken->fresh()->revoked_at)->not->toBeNull()
        ->and($refreshToken->fresh()->revoked_at)->not->toBeNull()
        ->and($connection->fresh()->revoked_at)->toBeNull();
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
