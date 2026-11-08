<?php

use App\Enums\TeamRole;
use App\Models\ArtifactIndexEntry;
use App\Models\Collection;
use App\Models\CollectionArtifact;
use App\Models\Team;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

function callCollectionTool(string $token, string $tool, array $arguments = []): TestResponse
{
    return test()->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => ['name' => $tool, 'arguments' => $arguments],
    ]);
}

test('add_collection_artifact rejects conflicting collection_id and collection without writing', function () {
    $team = Team::factory()->create();
    $token = remoteMcpToken($team);
    $launch = Collection::factory()->create(['team_id' => $team->id, 'name' => 'Launch']);
    $other = Collection::factory()->create(['team_id' => $team->id, 'name' => 'Other']);
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake([
        'worker.test/v1/artifacts/artifact123' => Http::response(['id' => 'artifact123'], 200),
    ]);

    $response = callCollectionTool($token, 'add_collection_artifact', [
        'collection_id' => $launch->id,
        'collection' => 'Other',
        'artifact_id' => 'artifact123',
    ])->assertOk();

    expect($response->json('result.isError'))->toBeTrue()
        ->and($response->json('result.content.0._meta.artfct.errorCode'))->toBe('invalid_request')
        ->and(CollectionArtifact::query()->where('artifact_id', 'artifact123')->exists())->toBeFalse();

    callCollectionTool($token, 'add_collection_artifact', [
        'collection_id' => $other->id,
        'collection' => 'Other',
        'artifact_id' => 'artifact123',
    ])->assertOk()
        ->assertJsonPath('result.structuredContent.collection_id', $other->id);

    expect(CollectionArtifact::query()
        ->where('collection_id', $other->id)
        ->where('artifact_id', 'artifact123')
        ->exists())->toBeTrue();
});

test('list_collections artifact_count excludes deleted artifacts that left stale memberships', function () {
    $team = Team::factory()->create();
    $token = remoteMcpToken($team);
    $creator = memberOfTeam($team, TeamRole::Member);
    $collection = Collection::create([
        'team_id' => $team->id,
        'name' => 'Live count',
        'created_by_user_id' => $creator->id,
    ]);

    CollectionArtifact::create(['collection_id' => $collection->id, 'artifact_id' => 'alive123', 'added_at' => now()]);
    CollectionArtifact::create(['collection_id' => $collection->id, 'artifact_id' => 'gone4567', 'added_at' => now()]);
    ArtifactIndexEntry::factory()->create([
        'team_id' => $team->id,
        'artifact_id' => 'alive123',
    ]);
    // gone4567 has a membership row but no index entry (deleted / purged).

    $listed = callCollectionTool($token, 'list_collections', ['limit' => 20])
        ->assertOk()
        ->json('result.structuredContent.collections');

    $row = collect($listed)->firstWhere('id', $collection->id);
    expect($row['artifact_count'])->toBe(1);
});

test('list_collections returns invalid_cursor for a bad cursor without reporting upstream_unavailable', function () {
    $team = Team::factory()->create();
    $token = remoteMcpToken($team);

    $response = callCollectionTool($token, 'list_collections', ['cursor' => 'not-a-cursor'])->assertOk();

    expect($response->json('result.isError'))->toBeTrue()
        ->and($response->json('result.content.0._meta.artfct.errorCode'))->toBe('invalid_cursor')
        ->and($response->json('result.content.0.text'))->toContain('cursor')
        ->and($response->json('result.content.0._meta.artfct.errorCode'))->not->toBe('upstream_unavailable');
});

test('create_collection reports that a duplicate name already exists', function () {
    $team = Team::factory()->create();
    $token = remoteMcpToken($team);
    Collection::factory()->create(['team_id' => $team->id, 'name' => 'Reports']);

    $response = callCollectionTool($token, 'create_collection', ['name' => 'Reports'])->assertOk();

    expect($response->json('result.isError'))->toBeTrue()
        ->and($response->json('result.content.0.text'))->toContain('already exists')
        ->and($response->json('result.content.0._meta.artfct.errorCode'))->toBe('collection_already_exists');
});

test('remove_collection_artifact and delete_collection require collections:write and mutate the workspace', function () {
    $team = Team::factory()->create();
    $token = remoteMcpToken($team);
    $collection = Collection::factory()->create(['team_id' => $team->id, 'name' => 'Scratch']);
    CollectionArtifact::create(['collection_id' => $collection->id, 'artifact_id' => 'artifact123', 'added_at' => now()]);

    callCollectionTool($token, 'remove_collection_artifact', [
        'collection' => 'Scratch',
        'artifact_id' => 'artifact123',
    ])->assertOk()
        ->assertJsonPath('result.structuredContent.removed', true);

    expect(CollectionArtifact::query()->where('collection_id', $collection->id)->exists())->toBeFalse();

    callCollectionTool($token, 'delete_collection', ['collection_id' => $collection->id])
        ->assertOk()
        ->assertJsonPath('result.structuredContent.deleted', true);

    expect(Collection::query()->whereKey($collection->id)->exists())->toBeFalse();

    $viewerToken = remoteMcpToken($team, TeamRole::Viewer);
    $denied = callCollectionTool($viewerToken, 'delete_collection', ['collection' => 'Nope'])->assertOk();
    expect(json_encode($denied->json()))->toContain('collections:write');
});

test('create_collection timestamps use the shared UTC Z format', function () {
    $team = Team::factory()->create();
    $token = remoteMcpToken($team);

    $created = callCollectionTool($token, 'create_collection', ['name' => 'Timestamped'])
        ->assertOk()
        ->json('result.structuredContent');

    expect($created['created_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/')
        ->and($created['updated_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');
});
