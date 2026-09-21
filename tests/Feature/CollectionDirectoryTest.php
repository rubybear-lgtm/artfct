<?php

use App\Enums\TeamRole;
use App\Models\Collection;
use App\Models\CollectionArtifact;
use App\Models\Team;
use App\Services\Auth\OrgJwtService;
use Illuminate\Support\Facades\Http;

function collectionDirectoryToken(Team $team): string
{
    configureSigning(testSigningKey());
    $user = memberOfTeam($team, TeamRole::Viewer);

    return OrgJwtService::default()->mint($team, $user, TeamRole::Viewer)['token'];
}

test('lists only the authenticated teams collections with bounded metadata and cursors', function () {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $creator = memberOfTeam($team, TeamRole::Member);

    $first = Collection::create([
        'team_id' => $team->id,
        'name' => 'Alpha',
        'description' => 'First collection',
        'canonical' => true,
        'created_by_user_id' => $creator->id,
    ]);
    Collection::create([
        'team_id' => $team->id,
        'name' => 'Beta',
        'created_by_user_id' => $creator->id,
    ]);
    Collection::create([
        'team_id' => $otherTeam->id,
        'name' => 'Secret',
        'created_by_user_id' => memberOfTeam($otherTeam, TeamRole::Member)->id,
    ]);
    CollectionArtifact::create([
        'collection_id' => $first->id,
        'artifact_id' => 'artifact-alpha',
        'added_at' => now(),
    ]);

    $response = $this->withToken(collectionDirectoryToken($team))
        ->getJson('/api/collections?limit=1')
        ->assertOk()
        ->assertJsonPath('collections.0.name', 'Alpha')
        ->assertJsonPath('collections.0.artifact_count', 1)
        ->assertJsonPath('collections.0.canonical', true)
        ->assertJsonMissingPath('collections.0.artifact_ids')
        ->assertJsonPath('collections.1.name', null);

    $cursor = $response->json('next_cursor');
    expect($cursor)->toBeString()->not->toBeEmpty();

    $this->withToken(collectionDirectoryToken($team))
        ->getJson('/api/collections?limit=1&cursor='.urlencode($cursor))
        ->assertOk()
        ->assertJsonPath('collections.0.name', 'Beta')
        ->assertJsonPath('next_cursor', null);
});

test('rejects malformed collection cursors', function () {
    $team = Team::factory()->create();

    $this->withToken(collectionDirectoryToken($team))
        ->getJson('/api/collections?cursor=not-a-cursor')
        ->assertUnprocessable();
});
test('example', function () {
    $response = $this->get('/');

    $response->assertStatus(200);
});

test('the REST API only adds artifacts the callers org can see', function () {
    $team = Team::factory()->create();
    configureSigning(testSigningKey());
    $member = memberOfTeam($team, TeamRole::Member);
    $token = OrgJwtService::default()->mint($team, $member, TeamRole::Member)['token'];
    $collection = Collection::create(['team_id' => $team->id, 'name' => 'Beta', 'created_by_user_id' => $member->id]);
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake([
        'worker.test/v1/artifacts/real123' => Http::response(['id' => 'real123'], 200),
        'worker.test/v1/artifacts/ghost123' => Http::response([], 404),
        'worker.test/v1/artifacts/down123' => Http::response([], 500),
    ]);

    $this->withToken($token)->postJson("/api/collections/{$collection->id}/artifacts", ['artifact_id' => 'real123'])
        ->assertOk()->assertJsonPath('artifact_id', 'real123');
    $this->withToken($token)->postJson("/api/collections/{$collection->id}/artifacts", ['artifact_id' => 'ghost123'])
        ->assertNotFound()->assertJsonPath('error', 'artifact_not_found');
    $this->withToken($token)->postJson("/api/collections/{$collection->id}/artifacts", ['artifact_id' => 'down123'])
        ->assertStatus(503);

    expect(CollectionArtifact::query()->where('collection_id', $collection->id)->pluck('artifact_id')->all())->toBe(['real123']);
});
