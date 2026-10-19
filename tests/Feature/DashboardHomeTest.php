<?php

use App\Contracts\ArtifactDirectory;
use App\Enums\TeamRole;
use App\Http\Middleware\EnsureTeamMembership;
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

/**
 * @param  array<string, mixed>  $overrides
 */
function seedDashboardArtifact(Team $team, string $id, array $overrides = []): void
{
    /** @var FakeArtifactDirectory $directory */
    $directory = app(ArtifactDirectory::class);
    $directory->seedArtifact(array_merge([
        'id' => $id,
        'org_id' => $team->slug,
        'user_id' => 1,
        'title' => "Artifact {$id}",
        'description' => 'A test artifact.',
        'content_hash' => md5($id),
        'created_at' => now()->toIso8601String(),
        'revoked_at' => null,
        'provenance' => ['agent' => 'cursor'],
    ], $overrides));
}

/**
 * @param  array<string, mixed>  $provenance
 */
function seedDashboardChunk(Team $team, string $artifactId, string $text, array $provenance = []): void
{
    /** @var FakeVectorIndex $index */
    $index = app(VectorIndexContract::class);
    $index->upsertChunks($team->slug, $artifactId, [new VectorChunk(
        text: $text,
        vector: app(EmbeddingsContract::class)->embed([$text])[0],
        artifactId: $artifactId,
        orgId: $team->slug,
        createdAt: now()->toIso8601String(),
        agent: $provenance['agent'] ?? null,
        repoUrl: null,
        commitSha: null,
    )]);
}

test('the_dashboard_home_carries_team_recent_and_pinned_collections', function () {
    $team = Team::factory()->create(['slug' => 'home-org', 'name' => 'Home Org']);
    $member = memberOfTeam($team, TeamRole::Member);

    for ($i = 0; $i < 12; $i++) {
        seedDashboardArtifact($team, "artifact-{$i}", [
            'user_id' => $member->id,
            'created_at' => now()->subMinutes($i)->toIso8601String(),
        ]);
    }

    // Two canonical collections sort ahead of six plain ones, and only six
    // collections fit the cap.
    foreach (range(0, 7) as $i) {
        Collection::create([
            'team_id' => $team->id,
            'name' => "collection-{$i}",
            'canonical' => $i < 2,
            'created_by_user_id' => $member->id,
        ]);
    }
    $collecting = Collection::query()->where('name', 'collection-2')->firstOrFail();
    CollectionArtifact::create(['collection_id' => $collecting->id, 'artifact_id' => 'artifact-0', 'added_at' => now()]);

    test()->actingAs($member)->get(route('dashboard', $team))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('home.team.slug', 'home-org')
            ->where('home.filters.scope', 'team')
            ->has('home.recent', 10)
            ->where('home.recent.0.id', 'artifact-0')
            ->has('home.collections', 6)
            ->where('home.collections.0.canonical', true)
            ->where('home.collections.1.canonical', true)
            ->where('home.collections.2.canonical', false)
            ->where('home.collections.2.name', 'collection-2')
            ->where('home.collections.2.artifactCount', 1)
            ->where('home.collectionCount', 8)
            ->has('home.filterCollections', 8));
});

test('scope_mine_limits_recent_to_the_signed_in_user', function () {
    // The fake ships two demo rows owned by users 1 and 2; push the members'
    // ids clear of them so the filter is unambiguous.
    User::factory()->count(2)->create();
    $team = Team::factory()->create();
    $member = memberOfTeam($team, TeamRole::Member);
    $teammate = memberOfTeam($team, TeamRole::Member);

    seedDashboardArtifact($team, 'mine-1', ['user_id' => $member->id]);
    seedDashboardArtifact($team, 'theirs-1', ['user_id' => $teammate->id]);

    test()->actingAs($member)->get(route('dashboard', [$team, 'scope' => 'mine']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('home.filters.scope', 'mine')
            ->has('home.recent', 1)
            ->where('home.recent.0.id', 'mine-1'));
});

test('recent_rows_flag_revoked_and_resolve_the_team_members_name', function () {
    User::factory()->count(2)->create();
    $team = Team::factory()->create();
    $member = memberOfTeam($team, TeamRole::Member);
    $teammate = memberOfTeam($team, TeamRole::Member);
    $outsider = User::factory()->create();

    seedDashboardArtifact($team, 'revoked-row', [
        'user_id' => $teammate->id,
        'revoked_at' => now()->toIso8601String(),
    ]);
    seedDashboardArtifact($team, 'outsider-row', [
        'user_id' => $outsider->id,
        'created_at' => now()->subMinute()->toIso8601String(),
    ]);

    test()->actingAs($member)->get(route('dashboard', $team))
        ->assertInertia(fn (Assert $page) => $page
            ->where('home.recent.0.id', 'revoked-row')
            ->where('home.recent.0.revoked', true)
            ->where('home.recent.0.authorName', $teammate->name)
            ->where('home.recent.1.id', 'outsider-row')
            ->where('home.recent.1.revoked', false)
            ->where('home.recent.1.authorName', null));
});

test('a_search_returns_service_results_and_lists_no_recent_artifacts', function () {
    config(['indexing.enabled' => true]);
    $team = Team::factory()->create(['slug' => 'search-home-org']);
    $member = memberOfTeam($team, TeamRole::Member);

    $text = 'billing dashboard revenue overview';
    seedDashboardArtifact($team, 'billing-dash', ['title' => 'Billing Dashboard']);
    seedDashboardChunk($team, 'billing-dash', $text, ['agent' => 'cursor']);

    test()->actingAs($member)->get(route('dashboard', [$team, 'q' => $text]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('home.searched', true)
            ->where('home.results.0.id', 'billing-dash')
            ->where('home.recent', [])
            ->where('home.recentError', null));
});

test('the_directory_is_not_called_for_recent_while_searching', function () {
    // With indexing off SearchService is never reached, so the only possible
    // listArtifacts caller is the recent block, which must skip a q.
    config(['indexing.enabled' => false]);
    $team = Team::factory()->create();
    $member = memberOfTeam($team, TeamRole::Member);

    $directory = Mockery::mock(ArtifactDirectory::class);
    $directory->shouldNotReceive('listArtifacts');
    app()->instance(ArtifactDirectory::class, $directory);

    test()->actingAs($member)->get(route('dashboard', [$team, 'q' => 'billing']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('home.recent', [])
            ->where('home.recentError', null));
});

test('a_failing_directory_still_renders_the_dashboard_with_a_recent_error', function () {
    $team = Team::factory()->create();
    $member = memberOfTeam($team, TeamRole::Member);

    app()->instance(ArtifactDirectory::class, new class implements ArtifactDirectory
    {
        public function listArtifacts(string $orgSlug, array $filters = [], ?string $cursor = null, int $limit = 50): array
        {
            throw new RuntimeException('directory down');
        }

        public function getArtifact(string $orgSlug, string $artifactId): ?array
        {
            throw new RuntimeException('not implemented');
        }

        public function updateSharing(string $orgSlug, string $artifactId, array $changes): array
        {
            throw new RuntimeException('not implemented');
        }

        public function revokeArtifact(string $orgSlug, string $artifactId): array
        {
            throw new RuntimeException('not implemented');
        }

        public function exportArtifacts(string $orgSlug): array
        {
            throw new RuntimeException('not implemented');
        }

        public function fetchBlob(string $orgSlug, string $sha256): ?string
        {
            throw new RuntimeException('not implemented');
        }

        public function listVersions(string $orgSlug, string $artifactId): ?array
        {
            throw new RuntimeException('not implemented');
        }

        public function restoreVersion(string $orgSlug, string $artifactId, int $version): array
        {
            throw new RuntimeException('not implemented');
        }
    });

    test()->actingAs($member)->get(route('dashboard', $team))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('home.recent', [])
            ->where('home.recentError', 'Recent artifacts are unavailable right now.'));
});

test('a_user_with_no_current_team_gets_a_null_home', function () {
    $team = Team::factory()->create();
    $member = memberOfTeam($team, TeamRole::Member);

    test()->withoutMiddleware(EnsureTeamMembership::class)
        ->actingAs($member)
        ->get(route('dashboard', $team))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('home', null)
            ->where('setup', null));
});
