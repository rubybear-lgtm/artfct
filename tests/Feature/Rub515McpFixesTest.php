<?php

use App\Mcp\Support\McpTimestamp;
use App\Models\ArtifactIndexEntry;
use App\Models\Collection;
use App\Models\CollectionArtifact;
use App\Models\Team;
use App\Services\Collections\CollectionService;
use App\Services\Indexing\ArtifactTextPurger;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

const RUB515_ARTIFACT_ID = 'abc123def4567';

function callRub515Tool(string $token, string $tool, array $arguments = []): TestResponse
{
    return test()->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => ['name' => $tool, 'arguments' => $arguments],
    ]);
}

test('list_artifacts returns invalid_cursor for a garbage cursor instead of page 1', function () {
    $team = Team::factory()->create(['slug' => 'rub-515-list-cursor']);
    $token = remoteMcpToken($team);
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake([
        'worker.test/v1/orgs/*/artifacts*' => Http::response([
            'artifacts' => [['id' => RUB515_ARTIFACT_ID, 'title' => 'Should not list']],
            'next_cursor' => null,
        ]),
    ]);

    $response = callRub515Tool($token, 'list_artifacts', ['cursor' => 'not-a-cursor!!!'])->assertOk();

    expect($response->json('result.isError'))->toBeTrue()
        ->and($response->json('result.content.0._meta.artfct.errorCode'))->toBe('invalid_cursor')
        ->and($response->json('result.content.0.text'))->toContain('cursor');

    Http::assertNothingSent();
});

test('search_artifacts returns collection_not_found for an unknown collection like list_artifacts', function () {
    config(['indexing.enabled' => true]);
    $team = Team::factory()->create(['slug' => 'rub-515-search-collection']);
    $token = remoteMcpToken($team);

    $response = callRub515Tool($token, 'search_artifacts', [
        'query' => 'billing',
        'collection' => 'does-not-exist',
    ])->assertOk();

    expect($response->json('result.isError'))->toBeTrue()
        ->and($response->json('result.content.0._meta.artfct.errorCode'))->toBe('collection_not_found')
        ->and($response->json('result.content.0.text'))->toContain('collection');
});

test('search_artifacts accepts a collection numeric id and returns a hint when empty', function () {
    config(['indexing.enabled' => true]);
    $team = Team::factory()->create(['slug' => 'rub-515-search-hint']);
    $token = remoteMcpToken($team);
    $collection = Collection::factory()->create(['team_id' => $team->id, 'name' => 'Empty shelf']);

    $response = callRub515Tool($token, 'search_artifacts', [
        'query' => 'zzzz-no-such-topic-ever',
        'collection' => (string) $collection->id,
    ])->assertOk();

    expect($response->json('result.isError'))->toBeFalse()
        ->and($response->json('result.structuredContent.results'))->toBe([])
        ->and($response->json('result.structuredContent.hint'))->toBeString()
        ->and($response->json('result.structuredContent.hint'))->toContain('No artifacts matched');
});

test('delete_artifact forgets collection memberships and purges the index entry', function () {
    $team = Team::factory()->create(['slug' => 'rub-515-delete-purge']);
    $token = remoteMcpToken($team);
    $collection = Collection::factory()->create(['team_id' => $team->id]);
    CollectionArtifact::create([
        'collection_id' => $collection->id,
        'artifact_id' => RUB515_ARTIFACT_ID,
        'added_at' => now(),
    ]);
    ArtifactIndexEntry::factory()->create([
        'team_id' => $team->id,
        'artifact_id' => RUB515_ARTIFACT_ID,
    ]);
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake([
        'worker.test/v1/artifacts/'.RUB515_ARTIFACT_ID => Http::response(['deleted' => true], 200),
    ]);

    callRub515Tool($token, 'delete_artifact', ['id' => RUB515_ARTIFACT_ID])
        ->assertOk()
        ->assertJsonPath('result.structuredContent.deleted', true);

    expect(CollectionArtifact::query()->where('artifact_id', RUB515_ARTIFACT_ID)->exists())->toBeFalse()
        ->and(ArtifactIndexEntry::query()->where('team_id', $team->id)->where('artifact_id', RUB515_ARTIFACT_ID)->exists())->toBeFalse();
});

test('get_connection last_indexed_at uses the shared UTC Z timestamp format', function () {
    config(['indexing.enabled' => true]);
    $team = Team::factory()->create();
    $latest = ArtifactIndexEntry::factory()->create([
        'team_id' => $team->id,
        'extracted_at' => '2026-10-18 12:00:00',
    ]);
    $token = remoteMcpToken($team);

    $initialize = test()->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => ['protocolVersion' => '2025-11-25'],
    ])->assertOk();

    $search = test()->withToken($token)
        ->withHeader('MCP-Session-Id', $initialize->headers->get('MCP-Session-Id'))
        ->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => ['name' => 'get_connection', 'arguments' => []],
        ])->assertOk()
        ->json('result.structuredContent.search');

    expect($search['last_indexed_at'])->toBe(McpTimestamp::format($latest->extracted_at))
        ->and($search['last_indexed_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');
});

test('collection service forgetArtifact drops memberships across the team', function () {
    $team = Team::factory()->create();
    $a = Collection::factory()->create(['team_id' => $team->id]);
    $b = Collection::factory()->create(['team_id' => $team->id]);
    CollectionArtifact::create(['collection_id' => $a->id, 'artifact_id' => RUB515_ARTIFACT_ID, 'added_at' => now()]);
    CollectionArtifact::create(['collection_id' => $b->id, 'artifact_id' => RUB515_ARTIFACT_ID, 'added_at' => now()]);

    app(CollectionService::class)->forgetArtifact($team, RUB515_ARTIFACT_ID);

    expect(CollectionArtifact::query()->where('artifact_id', RUB515_ARTIFACT_ID)->count())->toBe(0);
});

test('artifact text purger removes the index entry used by list_collections counts', function () {
    $team = Team::factory()->create();
    ArtifactIndexEntry::factory()->create(['team_id' => $team->id, 'artifact_id' => RUB515_ARTIFACT_ID]);

    ArtifactTextPurger::purgeEverything($team, RUB515_ARTIFACT_ID);

    expect(ArtifactIndexEntry::query()->where('artifact_id', RUB515_ARTIFACT_ID)->exists())->toBeFalse();
});
