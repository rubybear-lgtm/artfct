<?php

use App\Enums\TeamRole;
use App\Models\McpConnection;
use App\Models\Team;

test('the migration drops the generated MCP suffix and leaves other names alone', function () {
    $team = Team::factory()->create();
    $member = memberOfTeam($team, TeamRole::Member);

    $generated = McpConnection::factory()->create([
        'team_id' => $team->id,
        'user_id' => $member->id,
        'client_name' => 'Cursor',
        'name' => 'Cursor MCP',
    ]);
    $chosen = McpConnection::factory()->create([
        'team_id' => $team->id,
        'user_id' => $member->id,
        'client_name' => 'Claude',
        'name' => 'Claude on laptop',
    ]);

    $migration = require database_path('migrations/2026_10_05_022225_strip_mcp_suffix_from_connection_names.php');
    $migration->up();

    expect($generated->fresh()->name)->toBe('Cursor')
        ->and($chosen->fresh()->name)->toBe('Claude on laptop');
});
