<?php

use App\Enums\TeamRole;
use App\Models\Collection;
use App\Models\CollectionArtifact;
use App\Models\Team;
use App\Services\Auth\OrgJwtService;

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
