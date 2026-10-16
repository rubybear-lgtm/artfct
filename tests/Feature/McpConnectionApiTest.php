<?php

use App\Enums\AuditEventType;
use App\Enums\TeamRole;
use App\Models\AuditEvent;
use App\Models\McpConnection;
use App\Models\Team;
use App\Services\Auth\OrgJwtService;
use Illuminate\Support\Carbon;

function mcpApiToken(Team $team, TeamRole $role = TeamRole::Member): string
{
    $key = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    openssl_pkey_export($key, $pem);
    config([
        'services.org_jwt.private_key' => $pem,
        'services.org_jwt.kid' => 'mcp-test-kid',
    ]);
    $user = memberOfTeam($team, $role);

    return OrgJwtService::default()->mint($team, $user, $role, 3600)['token'];
}

test('registering an MCP client creates a scoped connection without exposing credential material', function () {
    $team = Team::factory()->create();
    $token = mcpApiToken($team);

    $response = test()->withToken($token)->postJson('/api/mcp/connections', [
        'client_name' => 'cursor',
        'client_version' => '1.2.3',
        'host' => 'cursor',
        'transport' => 'stdio',
    ]);

    $response->assertCreated()
        ->assertJsonPath('organization', $team->slug)
        ->assertJsonPath('scopes.0', 'artifacts:read')
        ->assertJsonPath('scopes.1', 'artifacts:deploy')
        ->assertJsonMissingPath('credential_jti')
        ->assertJsonMissingPath('token');

    $connection = McpConnection::query()->where('public_id', $response->json('connection_id'))->firstOrFail();
    expect($connection->credential_jti)->not->toBeNull()
        ->and($connection->client_name)->toBe('cursor')
        ->and($connection->metadata)->toBe(['host' => 'cursor']);

    expect(AuditEvent::query()
        ->where('team_id', $team->id)
        ->where('event_type', AuditEventType::McpConnectionCreated)
        ->where('target', "mcp_connection:{$connection->public_id}")
        ->where('actor', (string) $connection->user_id)
        ->exists())->toBeTrue();
});

test('registering the same credential is idempotent and refreshes usage', function () {
    $team = Team::factory()->create();
    $token = mcpApiToken($team);
    $payload = [
        'client_name' => 'claude-code',
        'client_version' => '2.0.0',
        'host' => 'claude-code',
        'transport' => 'stdio',
    ];

    $first = test()->withToken($token)->postJson('/api/mcp/connections', $payload);
    $first->assertCreated();
    $firstId = $first->json('connection_id');

    Carbon::setTestNow(now()->addMinute());

    $second = test()->withToken($token)->postJson('/api/mcp/connections', $payload);

    $second->assertOk()->assertJsonPath('connection_id', $firstId);
    expect(McpConnection::query()->whereNotNull('credential_jti')->count())->toBe(1)
        ->and(McpConnection::query()->firstOrFail()->last_used_at?->toDateTimeString())
        ->toBe(now()->toDateTimeString());

    expect(AuditEvent::query()
        ->where('team_id', $team->id)
        ->where('event_type', AuditEventType::McpConnectionRefreshed)
        ->where('target', "mcp_connection:{$firstId}")
        ->count())->toBe(1);
});

test('viewers receive read-only scopes without mutation access', function () {
    $team = Team::factory()->create();
    $token = mcpApiToken($team, TeamRole::Viewer);

    test()->withToken($token)->postJson('/api/mcp/connections', [
        'client_name' => 'codex',
        'transport' => 'stdio',
    ])->assertCreated()
        ->assertJsonPath('scopes', ['artifacts:read', 'collections:read', 'usage:read']);
});

test('heartbeat updates usage and rejects a revoked connection', function () {
    $team = Team::factory()->create();
    $token = mcpApiToken($team);
    $registered = test()->withToken($token)->postJson('/api/mcp/connections', [
        'client_name' => 'gemini',
        'transport' => 'streamable-http',
    ])->assertCreated();
    $connection = McpConnection::query()->where('public_id', $registered->json('connection_id'))->firstOrFail();

    Carbon::setTestNow(now()->addMinutes(3));

    test()->withToken($token)->postJson('/api/mcp/connections/heartbeat')
        ->assertOk();
    expect($connection->fresh()->last_used_at?->toDateTimeString())
        ->toBe(now()->toDateTimeString());

    expect(AuditEvent::query()
        ->where('team_id', $team->id)
        ->where('event_type', AuditEventType::McpConnectionRefreshed)
        ->where('target', "mcp_connection:{$connection->public_id}")
        ->count())->toBe(1);

    $connection->forceFill(['revoked_at' => now()])->save();

    test()->withToken($token)->postJson('/api/mcp/connections/heartbeat')
        ->assertForbidden();
});
test('example', function () {
    $response = $this->get('/');

    $response->assertStatus(200);
});
