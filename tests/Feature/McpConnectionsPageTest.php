<?php

use App\Enums\AuditEventType;
use App\Enums\TeamPermission;
use App\Enums\TeamRole;
use App\Models\AuditEvent;
use App\Models\McpActivity;
use App\Models\McpConnection;
use App\Models\OAuthRefreshToken;
use App\Models\OrgToken;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('team members can view connection metadata without credentials', function () {
    $team = Team::factory()->create();
    $member = memberOfTeam($team, TeamRole::Member);
    $connection = McpConnection::factory()->create([
        'team_id' => $team->id,
        'user_id' => $member->id,
        'name' => 'Cursor on laptop',
    ]);

    test()->actingAs($member)->get(route('teams.mcp-connections.index', $team))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('teams/mcp-connections')
            ->where('mcpEndpoint', url('/mcp'))
            ->where('oauthMetadataUrl', url('/.well-known/oauth-protected-resource'))
            ->where('connections.0.id', $connection->public_id)
            ->where('connections.0.name', 'Cursor on laptop')
            ->where('connections.0.clientName', $connection->client_name)
            ->where('connections.0.canRevoke', true)
            ->where('scopeOptions', ['artifacts:read', 'artifacts:deploy', 'collections:read', 'collections:write', 'usage:read'])
            ->where('defaultScopes', ['artifacts:read', 'collections:read', 'usage:read'])
            ->has('activity', 0)
            ->missing('connections.0.token')
            ->missing('connections.0.accessToken'));
});

test('team members can inspect recent MCP activity without sensitive payloads', function () {
    $team = Team::factory()->create();
    $member = memberOfTeam($team, TeamRole::Member);
    McpActivity::factory()->create([
        'team_id' => $team->id,
        'tool' => 'search_artifacts',
        'request_id' => 'request-123',
    ]);

    test()->actingAs($member)->get(route('teams.mcp-connections.index', $team))
        ->assertInertia(fn (Assert $page) => $page
            ->where('activity.0.tool', 'search_artifacts')
            ->where('activity.0.requestId', 'request-123')
            ->missing('activity.0.query')
            ->missing('activity.0.html'));
});

test('the MCP page exposes a bounded usage summary for the last thirty days', function () {
    $team = Team::factory()->create();
    $member = memberOfTeam($team, TeamRole::Member);

    McpActivity::factory()->createMany([
        ['team_id' => $team->id, 'tool' => 'search_artifacts', 'outcome' => 'success', 'latency_ms' => 100],
        ['team_id' => $team->id, 'tool' => 'search_artifacts', 'outcome' => 'error', 'latency_ms' => 300],
        ['team_id' => $team->id, 'tool' => 'get_connection', 'outcome' => 'success', 'latency_ms' => 50],
        ['team_id' => $team->id, 'tool' => 'deploy_to_canvas', 'outcome' => 'success', 'latency_ms' => 800, 'created_at' => now()->subDays(31)],
    ]);

    test()->actingAs($member)->get(route('teams.mcp-connections.index', $team))
        ->assertInertia(fn (Assert $page) => $page
            ->where('usage.periodDays', 30)
            ->where('usage.calls', 3)
            ->where('usage.successfulCalls', 2)
            ->where('usage.failedCalls', 1)
            ->where('usage.successRate', 66.7)
            ->where('usage.averageLatencyMs', 150)
            ->where('usage.byTool.0.tool', 'search_artifacts')
            ->where('usage.byTool.0.calls', 2)
            ->where('usage.byTool.0.failedCalls', 1));
});

test('strangers cannot view another teams connections', function () {
    $team = Team::factory()->create();
    $stranger = User::factory()->create();

    test()->actingAs($stranger)->get(route('teams.mcp-connections.index', $team))->assertNotFound();
});

test('connection creators can revoke and the action is audited', function () {
    $team = Team::factory()->create();
    $creator = memberOfTeam($team, TeamRole::Member);
    $connection = McpConnection::factory()->create([
        'team_id' => $team->id,
        'user_id' => $creator->id,
    ]);

    test()->actingAs($creator)->delete(route('teams.mcp-connections.destroy', [$team, $connection->public_id]))
        ->assertRedirect();

    expect($connection->fresh()->revoked_at)->not->toBeNull()
        ->and(AuditEvent::query()
            ->where('event_type', AuditEventType::McpConnectionRevoked)
            ->where('target', "mcp_connection:{$connection->public_id}")
            ->exists())->toBeTrue();
});

test('members cannot revoke another users connection but member managers can', function () {
    $team = Team::factory()->create();
    $owner = memberOfTeam($team, TeamRole::Admin);
    $member = memberOfTeam($team, TeamRole::Member);
    $manager = memberOfTeam($team, TeamRole::Admin);
    $connection = McpConnection::factory()->create([
        'team_id' => $team->id,
        'user_id' => $owner->id,
    ]);

    test()->actingAs($member)->delete(route('teams.mcp-connections.destroy', [$team, $connection->public_id]))
        ->assertForbidden();

    expect($member->hasTeamPermission($team, TeamPermission::RemoveMember))->toBeFalse();

    test()->actingAs($manager)->delete(route('teams.mcp-connections.destroy', [$team, $connection->public_id]))
        ->assertRedirect();
});

test('a connection from another team cannot be revoked through a team route', function () {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $admin = memberOfTeam($team, TeamRole::Admin);
    $connection = McpConnection::factory()->create(['team_id' => $otherTeam->id]);

    test()->actingAs($admin)->delete(route('teams.mcp-connections.destroy', [$team, $connection->public_id]))
        ->assertNotFound();
});
test('team members can create a connection with safe default scopes and it is audited', function () {
    $team = Team::factory()->create();
    $member = memberOfTeam($team, TeamRole::Member);

    test()->actingAs($member)->post(route('teams.mcp-connections.store', $team), [
        'client_name' => 'custom MCP client',
    ])->assertRedirect();

    $connection = McpConnection::query()->where('team_id', $team->id)->sole();
    expect($connection->user_id)->toBe($member->id)
        ->and($connection->client_name)->toBe('custom MCP client')
        ->and($connection->scopes)->toBe(['artifacts:read', 'collections:read', 'usage:read'])
        ->and(AuditEvent::query()
            ->where('event_type', AuditEventType::McpConnectionCreated)
            ->where('target', "mcp_connection:{$connection->public_id}")
            ->where('actor', (string) $member->id)
            ->exists())->toBeTrue();
});

test('viewers cannot grant scopes beyond their team role when creating a connection', function () {
    $team = Team::factory()->create();
    $viewer = memberOfTeam($team, TeamRole::Viewer);

    test()->actingAs($viewer)->post(route('teams.mcp-connections.store', $team), [
        'client_name' => 'viewer agent',
        'scopes' => ['artifacts:read', 'artifacts:deploy', 'collections:write', 'usage:read'],
    ])->assertRedirect();

    $connection = McpConnection::query()->where('team_id', $team->id)->sole();
    expect($connection->scopes)->toBe(['artifacts:read', 'usage:read']);
});

test('forcing reauthorization revokes live credentials without revoking the connection', function () {
    $team = Team::factory()->create();
    $creator = memberOfTeam($team, TeamRole::Member);
    $connection = McpConnection::factory()->create([
        'team_id' => $team->id,
        'user_id' => $creator->id,
    ]);
    $orgToken = OrgToken::factory()->create([
        'team_id' => $team->id,
        'user_id' => $creator->id,
        'mcp_connection_id' => $connection->id,
    ]);
    $refreshToken = OAuthRefreshToken::factory()->create([
        'team_id' => $team->id,
        'user_id' => $creator->id,
        'mcp_connection_id' => $connection->id,
    ]);

    test()->actingAs($creator)->post(route('teams.mcp-connections.reauthorize', [$team, $connection->public_id]))
        ->assertRedirect();

    expect($orgToken->fresh()->revoked_at)->not->toBeNull()
        ->and($refreshToken->fresh()->revoked_at)->not->toBeNull()
        ->and($connection->fresh()->revoked_at)->toBeNull()
        ->and(AuditEvent::query()
            ->where('event_type', AuditEventType::McpConnectionReauthorized)
            ->where('target', "mcp_connection:{$connection->public_id}")
            ->where('actor', (string) $creator->id)
            ->exists())->toBeTrue();
});

test('example', function () {
    $response = $this->get('/');

    $response->assertStatus(200);
});
