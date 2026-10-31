<?php

use App\Mcp\Support\McpTimestamp;
use App\Models\ArtifactIndexEntry;
use App\Models\Team;

/**
 * Calls get_connection on the hosted server for a team and returns its search block.
 *
 * @return array<string, mixed>
 */
function connectionSearchBlock(Team $team): array
{
    $token = remoteMcpToken($team);

    $initialize = test()->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => ['protocolVersion' => '2025-11-25'],
    ])->assertOk();

    return test()->withToken($token)
        ->withHeader('MCP-Session-Id', $initialize->headers->get('MCP-Session-Id'))
        ->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => ['name' => 'get_connection', 'arguments' => []],
        ])->assertOk()
        ->json('result.structuredContent.search');
}

test('get_connection reports search enabled with indexed artifacts', function () {
    config(['indexing.enabled' => true]);
    $team = Team::factory()->create();
    ArtifactIndexEntry::factory()->count(2)->create(['team_id' => $team->id, 'extracted_at' => '2026-10-17 09:00:00']);
    $latest = ArtifactIndexEntry::factory()->create(['team_id' => $team->id, 'extracted_at' => '2026-10-18 12:00:00']);
    ArtifactIndexEntry::factory()->create();

    $search = connectionSearchBlock($team);

    expect($search['enabled'])->toBeTrue()
        ->and($search['indexed_artifacts'])->toBe(3)
        ->and($search['last_indexed_at'])->toBe(McpTimestamp::format($latest->extracted_at))
        ->and($search['last_indexed_at'])->toMatch(McpTimestamp::PATTERN);
});

test('get_connection last_indexed_at moves after a newer index entry is written', function () {
    config(['indexing.enabled' => true]);
    $team = Team::factory()->create();
    ArtifactIndexEntry::factory()->create([
        'team_id' => $team->id,
        'extracted_at' => '2026-10-18 12:00:00',
    ]);

    $before = connectionSearchBlock($team)['last_indexed_at'];

    ArtifactIndexEntry::factory()->create([
        'team_id' => $team->id,
        'extracted_at' => '2026-10-19 15:30:00',
    ]);

    $after = connectionSearchBlock($team)['last_indexed_at'];

    expect($before)->toBe('2026-10-18T12:00:00Z')
        ->and($after)->toBe('2026-10-19T15:30:00Z')
        ->and($after)->toMatch(McpTimestamp::PATTERN);
});

test('get_connection reports search enabled with nothing indexed', function () {
    config(['indexing.enabled' => true]);
    $team = Team::factory()->create();

    expect(connectionSearchBlock($team))->toBe([
        'enabled' => true,
        'indexed_artifacts' => 0,
        'last_indexed_at' => null,
    ]);
});

test('get_connection reports search disabled', function () {
    config(['indexing.enabled' => false]);
    $team = Team::factory()->create();

    expect(connectionSearchBlock($team)['enabled'])->toBeFalse();
});

test('McpTimestamp emits only the shared ISO 8601 Zulu shape', function () {
    expect(McpTimestamp::format('2026-10-18 12:00:00'))->toBe('2026-10-18T12:00:00Z')
        ->and(McpTimestamp::format('2026-10-18T12:00:00.618+00:00'))->toBe('2026-10-18T12:00:00Z')
        ->and(McpTimestamp::format(null))->toBeNull()
        ->and(McpTimestamp::format('2026-10-18T12:00:00Z'))->toMatch(McpTimestamp::PATTERN);
});
