<?php

use App\Contracts\ArtifactDirectory;
use App\Enums\AuditEventType;
use App\Enums\TeamRole;
use App\Models\AuditEvent;
use App\Models\Collection;
use App\Models\SearchResultServed;
use App\Models\Team;
use App\Services\Artifacts\FakeArtifactDirectory;
use App\Services\Auth\OrgJwtService;
use App\Services\Indexing\EmbeddingsContract;
use App\Services\Indexing\FakeVectorIndex;
use App\Services\Indexing\VectorChunk;
use App\Services\Indexing\VectorIndexContract;
use App\Services\Search\SearchService;

/**
 * @param  array<string, mixed>  $overrides
 */
function seedSearchArtifact(Team $team, string $id, array $overrides = []): void
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
        'created_at' => now()->subDays(1)->toIso8601String(),
        'revoked_at' => null,
        'provenance' => [
            'agent' => 'cursor',
            'repo_url' => 'https://github.com/acme/misc',
            'commit_sha' => 'deadbeef',
        ],
    ], $overrides));
}

function seedSearchChunk(Team $team, string $artifactId, string $text, ?array $vectorOverride = null, array $provenance = []): void
{
    /** @var FakeVectorIndex $vectorIndex */
    $vectorIndex = app(VectorIndexContract::class);
    $vector = $vectorOverride ?? app(EmbeddingsContract::class)->embed([$text])[0];

    $vectorIndex->upsertChunks($team->slug, $artifactId, [
        new VectorChunk(
            text: $text,
            vector: $vector,
            artifactId: $artifactId,
            orgId: $team->slug,
            createdAt: now()->subDays(1)->toIso8601String(),
            agent: $provenance['agent'] ?? 'cursor',
            repoUrl: $provenance['repo_url'] ?? 'https://github.com/acme/misc',
            commitSha: $provenance['commit_sha'] ?? 'deadbeef',
        ),
    ]);
}

test('semantic_query_finds_relevant_artifact', function () {
    $team = Team::factory()->create(['slug' => 'search-org']);
    seedSearchArtifact($team, 'billing-dash', ['title' => 'Billing Dashboard']);

    // 999 unrelated decoys plus the one relevant artifact — "an org with
    // 1,000 artifacts."
    for ($i = 0; $i < 999; $i++) {
        $decoyId = "decoy-{$i}";
        seedSearchArtifact($team, $decoyId);
        seedSearchChunk($team, $decoyId, "unrelated content number {$i}");
    }
    seedSearchChunk($team, 'billing-dash', 'billing dashboard revenue overview');

    $results = app(SearchService::class)->search(
        $team,
        query: 'billing dashboard revenue overview',
        filters: [],
        limit: 5,
        actor: 'tester',
    );

    expect($results)->not->toBeEmpty();
    expect($results[0]->id)->toBe('billing-dash');
});

test('search_results_link_to_the_short_viewer_link', function () {
    config([
        'app.public_base_url' => null,
        'services.worker.base_url' => 'https://staging-worker.example.test',
    ]);

    $team = Team::factory()->create(['slug' => 'search-org']);
    $artifactId = str_repeat('a', 32);
    seedSearchArtifact($team, $artifactId);
    seedSearchChunk($team, $artifactId, 'staging worker host');

    $results = app(SearchService::class)->search($team, 'staging worker host', [], 5, 'tester');

    expect($results[0]->url)->toBe(route('artifacts.show', ['artifactId' => $artifactId]));
});

test('api_search_explains_when_indexing_is_disabled', function () {
    config(['indexing.enabled' => false]);

    $team = Team::factory()->create(['slug' => 'api-search-disabled-org']);

    $this->withToken(remoteMcpToken($team))
        ->postJson('/api/search', ['query' => 'billing dashboard'])
        ->assertStatus(503)
        ->assertJson([
            'errorCode' => 'search_not_configured',
            'message' => 'Search is not enabled for this workspace yet.',
            'retryable' => false,
            'nextAction' => 'enable_indexing',
        ]);
});

test('api_search_requires_artifact_read_scope', function () {
    config(['indexing.enabled' => false]);
    configureSigning(testSigningKey());

    $team = Team::factory()->create(['slug' => 'api-search-scoped-org']);
    $user = memberOfTeam($team, TeamRole::Viewer);

    $withoutReadScope = OrgJwtService::default()->mint($team, $user, TeamRole::Viewer, scopes: ['usage:read']);
    $withReadScope = OrgJwtService::default()->mint($team, $user, TeamRole::Viewer, scopes: ['artifacts:read']);

    $this->withToken($withoutReadScope['token'])
        ->postJson('/api/search', ['query' => 'billing dashboard'])
        ->assertForbidden();

    $this->withToken($withReadScope['token'])
        ->postJson('/api/search', ['query' => 'billing dashboard'])
        ->assertStatus(503)
        ->assertJsonPath('errorCode', 'search_not_configured');
});

test('repo_filter_narrows_results', function () {
    $team = Team::factory()->create(['slug' => 'repo-org']);
    seedSearchArtifact($team, 'in-repo', ['provenance' => ['agent' => 'cursor', 'repo_url' => 'https://github.com/acme/billing', 'commit_sha' => 'a']]);
    seedSearchArtifact($team, 'out-of-repo', ['provenance' => ['agent' => 'cursor', 'repo_url' => 'https://github.com/acme/other', 'commit_sha' => 'b']]);
    seedSearchChunk($team, 'in-repo', 'dashboard content', provenance: ['repo_url' => 'https://github.com/acme/billing']);
    seedSearchChunk($team, 'out-of-repo', 'dashboard content', provenance: ['repo_url' => 'https://github.com/acme/other']);

    $results = app(SearchService::class)->search(
        $team,
        query: 'dashboard content',
        filters: ['repo' => 'https://github.com/acme/billing'],
        limit: 10,
        actor: 'tester',
    );

    expect($results)->toHaveCount(1);
    expect($results[0]->id)->toBe('in-repo');
});

test('since_filter_excludes_older', function () {
    $team = Team::factory()->create(['slug' => 'since-org']);
    seedSearchArtifact($team, 'old-artifact', ['created_at' => now()->subDays(30)->toIso8601String()]);
    seedSearchArtifact($team, 'new-artifact', ['created_at' => now()->subDays(1)->toIso8601String()]);
    seedSearchChunk($team, 'old-artifact', 'dashboard content');
    seedSearchChunk($team, 'new-artifact', 'dashboard content');

    $results = app(SearchService::class)->search(
        $team,
        query: 'dashboard content',
        filters: ['since' => now()->subDays(7)->toIso8601String()],
        limit: 10,
        actor: 'tester',
    );

    $ids = array_map(fn ($r) => $r->id, $results);
    expect($ids)->toContain('new-artifact');
    expect($ids)->not->toContain('old-artifact');
});

test('exact_repo_match_outranks_semantic_match', function () {
    $team = Team::factory()->create(['slug' => 'rank-org']);
    seedSearchArtifact($team, 'billing-repo-artifact', ['provenance' => ['agent' => 'cursor', 'repo_url' => 'https://github.com/acme/billing', 'commit_sha' => 'a']]);
    seedSearchArtifact($team, 'unrelated-repo-artifact', ['provenance' => ['agent' => 'cursor', 'repo_url' => 'https://github.com/acme/unrelated', 'commit_sha' => 'b']]);

    $query = 'the billing dashboard';
    $queryVector = app(EmbeddingsContract::class)->embed([$query])[0];

    // The unrelated-repo chunk is given the query's OWN vector — perfect
    // cosine similarity (1.0), the maximum possible. The billing-repo
    // chunk is deliberately less similar (0.0 on the first two
    // dimensions), so on semantic similarity alone the unrelated-repo
    // artifact wins outright. The repo-match boost (SearchRanking) must
    // still put the billing-repo artifact first — DoD: "An exact repo
    // match should outrank a semantically closer artifact from an
    // unrelated repo."
    seedSearchChunk($team, 'unrelated-repo-artifact', 'irrelevant text', vectorOverride: $queryVector, provenance: ['repo_url' => 'https://github.com/acme/unrelated']);
    // Deterministically less-than-perfect similarity (one dimension
    // halved) but still comfortably above 0.5 — enough for the 0.5
    // repo-match boost to overtake the unrelated artifact's perfect 1.0
    // similarity, however FakeEmbeddings happens to hash this query text.
    $slightlyPerturbedVector = $queryVector;
    $slightlyPerturbedVector[0] *= 0.5;
    seedSearchChunk($team, 'billing-repo-artifact', 'somewhat related content', vectorOverride: $slightlyPerturbedVector, provenance: ['repo_url' => 'https://github.com/acme/billing']);

    $results = app(SearchService::class)->search(
        $team,
        query: $query,
        filters: [],
        limit: 10,
        actor: 'tester',
    );

    expect($results)->not->toBeEmpty();
    expect($results[0]->id)->toBe('billing-repo-artifact');
});

test('search_scoped_to_credential_org', function () {
    $teamA = Team::factory()->create(['slug' => 'org-a']);
    $teamB = Team::factory()->create(['slug' => 'org-b']);
    seedSearchArtifact($teamB, 'org-b-artifact');
    seedSearchChunk($teamB, 'org-b-artifact', 'shared query text');

    $results = app(SearchService::class)->search(
        $teamA,
        query: 'shared query text',
        filters: [],
        limit: 10,
        actor: 'tester',
    );

    expect($results)->toBeEmpty();
});

test('revoked_artifact_absent_from_results', function () {
    $team = Team::factory()->create(['slug' => 'revoke-org']);
    seedSearchArtifact($team, 'revoked-artifact', ['revoked_at' => now()->toIso8601String()]);
    seedSearchChunk($team, 'revoked-artifact', 'revoked content query');

    $results = app(SearchService::class)->search(
        $team,
        query: 'revoked content query',
        filters: [],
        limit: 10,
        actor: 'tester',
    );

    expect($results)->toBeEmpty();
});

test('inaccessible_artifact_indistinguishable_from_absent', function () {
    $team = Team::factory()->create(['slug' => 'inaccessible-org']);
    // A chunk exists in the vector index for an artifact the directory
    // never returns (deleted from the directory but not yet purged from
    // the index) — it must simply vanish from results, the same as a
    // revoked artifact or a query that matches nothing at all: no error,
    // no special marker.
    seedSearchChunk($team, 'ghost-artifact', 'ghost content query');

    $results = app(SearchService::class)->search(
        $team,
        query: 'ghost content query',
        filters: [],
        limit: 10,
        actor: 'tester',
    );

    expect($results)->toBeEmpty();
});

test('search_writes_audit_event', function () {
    $team = Team::factory()->create(['slug' => 'audit-org']);
    seedSearchArtifact($team, 'audited-artifact');
    seedSearchChunk($team, 'audited-artifact', 'auditable query text');

    app(SearchService::class)->search(
        $team,
        query: 'auditable query text',
        filters: [],
        limit: 10,
        actor: 'tester@example.com',
    );

    $event = AuditEvent::query()->where('event_type', AuditEventType::SearchPerformed)->firstOrFail();
    expect($event->actor)->toBe('tester@example.com');
    expect($event->target)->toBe('auditable query text');
});

test('search_records_a_search_results_served_row_per_returned_artifact', function () {
    $team = Team::factory()->create(['slug' => 'served-org']);
    seedSearchArtifact($team, 'served-artifact');
    seedSearchChunk($team, 'served-artifact', 'servable content query');

    $results = app(SearchService::class)->search(
        $team,
        query: 'servable content query',
        filters: [],
        limit: 10,
        actor: 'tester',
    );

    expect($results)->not->toBeEmpty();
    expect(SearchResultServed::query()
        ->where('team_id', $team->id)
        ->where('artifact_id', 'served-artifact')
        ->exists())->toBeTrue();
});

test('a_search_that_returns_nothing_records_no_served_rows', function () {
    $team = Team::factory()->create(['slug' => 'no-results-org']);

    app(SearchService::class)->search(
        $team,
        query: 'nothing matches this at all',
        filters: [],
        limit: 10,
        actor: 'tester',
    );

    expect(SearchResultServed::query()->where('team_id', $team->id)->exists())->toBeFalse();
});

test('search_under_500ms_at_1k_artifacts', function () {
    $team = Team::factory()->create(['slug' => 'perf-org']);
    for ($i = 0; $i < 1000; $i++) {
        $id = "perf-artifact-{$i}";
        seedSearchArtifact($team, $id);
        seedSearchChunk($team, $id, "content for artifact {$i}");
    }

    /** @var FakeArtifactDirectory $directory */
    $directory = app(ArtifactDirectory::class);
    $listCallsBefore = $directory->listCallCount();

    $start = microtime(true);
    $results = app(SearchService::class)->search(
        $team,
        query: 'content for artifact 500',
        filters: [],
        limit: 5,
        actor: 'tester',
    );
    $elapsedMs = (microtime(true) - $start) * 1000;

    expect($results)->not->toBeEmpty();
    expect($elapsedMs)->toBeLessThan(500);
    // Candidate budget is max(limit*5, 50) < 200 Worker ids/page → one list call.
    expect($directory->listCallCount() - $listCallsBefore)->toBe(1);
});

test('search_worker_calls_stay_bounded_at_10k_artifacts', function () {
    $team = Team::factory()->create(['slug' => 'bound-org']);
    for ($i = 0; $i < 10000; $i++) {
        $id = "bound-artifact-{$i}";
        seedSearchArtifact($team, $id);
        seedSearchChunk($team, $id, "content for artifact {$i}");
    }

    /** @var FakeArtifactDirectory $directory */
    $directory = app(ArtifactDirectory::class);
    $listCallsBefore = $directory->listCallCount();

    $results = app(SearchService::class)->search(
        $team,
        query: 'content for artifact 5000',
        filters: [],
        limit: 5,
        actor: 'tester',
    );

    expect($results)->not->toBeEmpty();
    // Same O(results) bound as the 1k case — must not grow with corpus size.
    expect($directory->listCallCount() - $listCallsBefore)->toBe(1);
});

test('empty_laravel_scope_skips_worker_list_calls', function () {
    $team = Team::factory()->create(['slug' => 'empty-scope-org']);
    seedSearchArtifact($team, 'scoped-artifact');
    seedSearchChunk($team, 'scoped-artifact', 'scoped content');

    /** @var FakeArtifactDirectory $directory */
    $directory = app(ArtifactDirectory::class);
    $listCallsBefore = $directory->listCallCount();

    // An existing collection with no members is an empty scope. (An unknown
    // collection is an error instead, RUB-515.)
    Collection::factory()->create(['team_id' => $team->id, 'name' => 'empty-collection']);

    $results = app(SearchService::class)->search(
        $team,
        query: 'scoped content',
        filters: ['collection' => 'empty-collection'],
        limit: 5,
        actor: 'tester',
    );

    expect($results)->toBeEmpty();
    expect($directory->listCallCount() - $listCallsBefore)->toBe(0);
});

test('a_narrow_repo_filter_over_a_large_org_still_finds_its_matches', function () {
    $team = Team::factory()->create(['slug' => 'narrow-filter-org']);

    // Far more equally relevant artifacts than the candidate limit (50) in one
    // repo, and two in another. The filter must narrow retrieval first; applied
    // after the candidate cut the two target artifacts fall outside the top 50.
    for ($i = 0; $i < 130; $i++) {
        seedSearchArtifact($team, "misc-{$i}");
        seedSearchChunk($team, "misc-{$i}", 'shared roadmap notes');
    }
    foreach (['target-1', 'target-2'] as $id) {
        seedSearchArtifact($team, $id, ['provenance' => ['agent' => 'cursor', 'repo_url' => 'https://github.com/acme/target', 'commit_sha' => 'cafe']]);
        seedSearchChunk($team, $id, 'shared roadmap notes');
    }

    $results = app(SearchService::class)->search(
        $team,
        query: 'shared roadmap notes',
        filters: ['repo' => 'https://github.com/acme/target'],
        limit: 5,
        actor: 'tester',
    );

    expect(array_map(fn ($result) => $result->id, $results))->toEqualCanonicalizing(['target-1', 'target-2']);
});

test('a_filter_that_matches_no_artifact_skips_embeddings_and_returns_nothing', function () {
    $team = Team::factory()->create(['slug' => 'empty-filter-org']);
    seedSearchArtifact($team, 'only-one');
    seedSearchChunk($team, 'only-one', 'some content');

    $embeddings = new class implements EmbeddingsContract
    {
        public int $calls = 0;

        public function embed(array $texts): array
        {
            $this->calls++;

            return array_map(fn () => array_fill(0, 8, 0.1), $texts);
        }

        public function embedQuery(string $query): array
        {
            return $this->embed([$query])[0];
        }
    };

    $results = app()->make(SearchService::class, ['embeddings' => $embeddings])->search(
        $team,
        query: 'some content',
        filters: ['agent' => 'an-agent-nobody-used'],
        limit: 5,
        actor: 'tester',
    );

    expect($results)->toBe([])->and($embeddings->calls)->toBe(0);
});

test('hidden_rows_crowding_the_candidates_trigger_one_widening_pass', function () {
    $team = Team::factory()->create(['slug' => 'crowded-org']);

    // Indexed passages the Worker does not list for this viewer (another
    // member's private artifacts: chunks, but no directory rows) crowd the
    // 50 candidate slots; the second visible artifact sits beyond them.
    for ($i = 0; $i < 30; $i++) {
        seedSearchChunk($team, "hidden-{$i}", 'shared roadmap notes');
    }
    // One visible artifact inside the first 50 (so the first pass keeps a row
    // the merge must preserve) and one beyond it that only the widened pass sees.
    seedSearchArtifact($team, 'visible-early');
    seedSearchChunk($team, 'visible-early', 'shared roadmap notes');
    for ($i = 30; $i < 100; $i++) {
        seedSearchChunk($team, "hidden-{$i}", 'shared roadmap notes');
    }
    seedSearchArtifact($team, 'visible-late');
    seedSearchChunk($team, 'visible-late', 'shared roadmap notes');

    /** @var FakeArtifactDirectory $directory */
    $directory = app(ArtifactDirectory::class);
    $listCallsBefore = $directory->listCallCount();

    $results = app(SearchService::class)->search(
        $team,
        query: 'shared roadmap notes',
        filters: [],
        limit: 5,
        actor: 'tester',
    );

    expect(array_map(fn ($result) => $result->id, $results))->toEqualCanonicalizing(['visible-early', 'visible-late']);
    // First pass (50 ids, one call) plus the widened pass for the ids not yet seen.
    expect($directory->listCallCount() - $listCallsBefore)->toBeLessThanOrEqual(2);
});

test('a_search_with_enough_visible_candidates_does_not_widen', function () {
    $team = Team::factory()->create(['slug' => 'no-widen-org']);

    for ($i = 0; $i < 80; $i++) {
        seedSearchArtifact($team, "open-{$i}");
        seedSearchChunk($team, "open-{$i}", 'shared roadmap notes');
    }

    /** @var FakeArtifactDirectory $directory */
    $directory = app(ArtifactDirectory::class);
    $listCallsBefore = $directory->listCallCount();

    $results = app(SearchService::class)->search(
        $team,
        query: 'shared roadmap notes',
        filters: [],
        limit: 5,
        actor: 'tester',
    );

    expect($results)->not->toBeEmpty();
    expect($directory->listCallCount() - $listCallsBefore)->toBe(1);
});
