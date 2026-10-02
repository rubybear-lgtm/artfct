<?php

use App\Contracts\ArtifactDirectory;
use App\Enums\TeamRole;
use App\Models\Collection;
use App\Models\CollectionArtifact;
use App\Models\Team;
use App\Models\User;
use App\Services\Artifacts\FakeArtifactDirectory;
use App\Services\Indexing\EmbeddingsContract;
use App\Services\Indexing\FakeVectorIndex;
use App\Services\Indexing\VectorChunk;
use App\Services\Indexing\VectorIndexContract;
use Inertia\Testing\AssertableInertia as Assert;

test('the_search_page_explains_when_indexing_is_off', function () {
    config(['indexing.enabled' => false]);
    $team = Team::factory()->create();
    $member = memberOfTeam($team, TeamRole::Member);

    test()->actingAs($member)->get(route('teams.search', [$team, 'q' => 'billing']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('teams/search')
            ->where('indexingEnabled', false)
            ->where('searched', false)
            ->where('results', []));
});

test('a_member_search_runs_and_lists_the_teams_collections', function () {
    config(['indexing.enabled' => true]);
    $team = Team::factory()->create();
    $member = memberOfTeam($team, TeamRole::Member);
    Collection::create(['team_id' => $team->id, 'name' => 'reporting', 'created_by_user_id' => $member->id]);

    test()->actingAs($member)->get(route('teams.search', [$team, 'q' => 'billing']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('indexingEnabled', true)
            ->where('searched', true)
            ->where('collections.0.name', 'reporting'));
});

test('another_teams_collections_never_appear', function () {
    $team = Team::factory()->create();
    $member = memberOfTeam($team, TeamRole::Member);
    $other = Team::factory()->create();
    Collection::create(['team_id' => $other->id, 'name' => 'secret', 'created_by_user_id' => $member->id]);

    test()->actingAs($member)->get(route('teams.search', $team))
        ->assertInertia(fn (Assert $page) => $page->where('collections', []));
});

test('non_members_get_a_404_for_the_search_page', function () {
    $team = Team::factory()->create();

    test()->actingAs(User::factory()->create())->get(route('teams.search', $team))->assertNotFound();
});

test('the_search_page_returns_the_teams_matches_and_flags_canonical_ones', function () {
    config(['indexing.enabled' => true]);
    $team = Team::factory()->create(['slug' => 'find-org']);
    $member = memberOfTeam($team, TeamRole::Member);

    /** @var FakeArtifactDirectory $directory */
    $directory = app(ArtifactDirectory::class);
    foreach (['billing-dash', 'other-doc'] as $id) {
        $directory->seedArtifact([
            'id' => $id, 'org_id' => $team->slug, 'user_id' => 1, 'title' => "Artifact {$id}", 'description' => 'd',
            'content_hash' => md5($id), 'created_at' => now()->subDay()->toIso8601String(), 'revoked_at' => null,
            'provenance' => ['agent' => 'cursor', 'repo_url' => 'https://github.com/acme/misc', 'commit_sha' => 'abc'],
        ]);
        /** @var FakeVectorIndex $index */
        $index = app(VectorIndexContract::class);
        $text = $id === 'billing-dash' ? 'billing dashboard revenue overview' : 'completely unrelated words';
        $index->upsertChunks($team->slug, $id, [new VectorChunk(
            text: $text, vector: app(EmbeddingsContract::class)->embed([$text])[0], artifactId: $id, orgId: $team->slug,
            createdAt: now()->subDay()->toIso8601String(), agent: 'cursor', repoUrl: 'https://github.com/acme/misc', commitSha: 'abc',
        )]);
    }
    $collection = Collection::create(['team_id' => $team->id, 'name' => 'canon', 'canonical' => true, 'created_by_user_id' => $member->id]);
    CollectionArtifact::create(['collection_id' => $collection->id, 'artifact_id' => 'billing-dash', 'added_at' => now()]);

    test()->actingAs($member)->get(route('teams.search', [$team, 'q' => 'billing dashboard revenue overview']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('searched', true)
            ->where('results.0.id', 'billing-dash')
            ->where('results.0.canonical', true));
});

test('provenance_and_date_filters_narrow_the_results', function () {
    config(['indexing.enabled' => true]);
    $team = Team::factory()->create(['slug' => 'filter-org']);
    $member = memberOfTeam($team, TeamRole::Member);

    /** @var FakeArtifactDirectory $directory */
    $directory = app(ArtifactDirectory::class);
    /** @var FakeVectorIndex $index */
    $index = app(VectorIndexContract::class);
    $text = 'billing dashboard revenue overview';
    foreach ([['old-cursor', 'cursor', 10], ['new-claude', 'claude', 1]] as [$id, $agent, $daysAgo]) {
        $createdAt = now()->subDays($daysAgo)->toIso8601String();
        $directory->seedArtifact([
            'id' => $id, 'org_id' => $team->slug, 'user_id' => 1, 'title' => "Artifact {$id}", 'description' => 'd',
            'content_hash' => md5($id), 'created_at' => $createdAt, 'revoked_at' => null,
            'provenance' => ['agent' => $agent, 'repo_url' => 'https://github.com/acme/misc', 'commit_sha' => 'abc'],
        ]);
        $index->upsertChunks($team->slug, $id, [new VectorChunk(
            text: $text, vector: app(EmbeddingsContract::class)->embed([$text])[0], artifactId: $id, orgId: $team->slug,
            createdAt: $createdAt, agent: $agent, repoUrl: 'https://github.com/acme/misc', commitSha: 'abc',
        )]);
    }

    test()->actingAs($member)->get(route('teams.search', [$team, 'q' => $text, 'agent' => 'claude']))
        ->assertInertia(fn (Assert $page) => $page->has('results', 1)->where('results.0.id', 'new-claude'));

    test()->actingAs($member)->get(route('teams.search', [$team, 'q' => $text, 'since' => now()->subDays(3)->toDateString()]))
        ->assertInertia(fn (Assert $page) => $page->has('results', 1)->where('results.0.id', 'new-claude')->where('filters.since', now()->subDays(3)->toDateString()));
});
