<?php

use App\Contracts\ArtifactDirectory;
use App\Enums\TeamRole;
use App\Enums\UsageEventType;
use App\Models\ArtifactUsageEvent;
use App\Models\Collection;
use App\Models\Team;
use App\Models\User;
use App\Services\Artifacts\FakeArtifactDirectory;
use App\Services\Collections\CollectionService;
use App\Services\Collections\UsageScorer;
use App\Services\Indexing\EmbeddingsContract;
use App\Services\Indexing\FakeVectorIndex;
use App\Services\Indexing\VectorChunk;
use App\Services\Indexing\VectorIndexContract;
use App\Services\Search\SearchService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;

function usageEvent(Team $team, string $artifactId, UsageEventType $type, ?int $actorUserId, ?CarbonInterface $occurredAt = null, ?string $relatedArtifactId = null): ArtifactUsageEvent
{
    $event = new ArtifactUsageEvent([
        'team_id' => $team->id,
        'artifact_id' => $artifactId,
        'event_type' => $type,
        'actor_user_id' => $actorUserId,
        'related_artifact_id' => $relatedArtifactId,
        'occurred_at' => $occurredAt ?? now(),
    ]);
    $event->save();

    return $event;
}

test('repeat_views_raise_ranking', function () {
    $team = Team::factory()->create();
    $u1 = User::factory()->create();
    $u2 = User::factory()->create();
    $u3 = User::factory()->create();
    $u4 = User::factory()->create();
    $u5 = User::factory()->create();

    $popularEvents = collect([$u1, $u2, $u3, $u4, $u5])
        ->map(fn ($u) => usageEvent($team, 'popular', UsageEventType::Viewed, $u->id))
        ->all();
    $onceEvents = [usageEvent($team, 'once', UsageEventType::Viewed, $u1->id)];

    $now = Carbon::now();
    expect(UsageScorer::score($popularEvents, $now))->toBeGreaterThan(UsageScorer::score($onceEvents, $now));
});

test('slack_share_raises_ranking', function () {
    $team = Team::factory()->create();
    $sharedEvents = [usageEvent($team, 'shared', UsageEventType::SlackShared, null)];
    $unsharedEvents = [];

    $now = Carbon::now();
    expect(UsageScorer::score($sharedEvents, $now))->toBeGreaterThan(UsageScorer::score($unsharedEvents, $now));
});

test('retrieved_then_opened_scores_above_retrieved_then_ignored', function () {
    $team = Team::factory()->create();
    $openedEvents = [usageEvent($team, 'opened', UsageEventType::RetrievedThenOpened, null)];
    $ignoredEvents = []; // a retrieval that was never opened logs nothing extra

    $now = Carbon::now();
    expect(UsageScorer::score($openedEvents, $now))->toBeGreaterThan(UsageScorer::score($ignoredEvents, $now));
});

test('superseded_artifact_ranks_below_successor', function () {
    $team = Team::factory()->create();
    $u1 = User::factory()->create();
    $u2 = User::factory()->create();
    $u3 = User::factory()->create();

    // The old artifact has *heavy* historical usage...
    $oldEvents = collect([$u1, $u2, $u3])
        ->map(fn ($u) => usageEvent($team, 'old', UsageEventType::Viewed, $u->id))
        ->push(usageEvent($team, 'old', UsageEventType::Superseded, null, relatedArtifactId: 'new'))
        ->all();
    // ...but the new one has none yet.
    $newEvents = [];

    $now = Carbon::now();
    expect(UsageScorer::score($oldEvents, $now))->toBe(0.0);
    expect(UsageScorer::score($newEvents, $now))->toBeGreaterThanOrEqual(UsageScorer::score($oldEvents, $now));
});

test('usage_signal_decays_over_time', function () {
    $team = Team::factory()->create();
    $now = Carbon::now();

    $recentEvents = [usageEvent($team, 'recent', UsageEventType::SlackShared, null, $now->copy()->subDay())];
    $staleEvents = [usageEvent($team, 'stale', UsageEventType::SlackShared, null, $now->copy()->subMonths(6))];

    expect(UsageScorer::score($recentEvents, $now))->toBeGreaterThan(UsageScorer::score($staleEvents, $now));
    // Six months out, with a 30-day half-life, is far enough decayed that
    // a single stale share no longer meaningfully outranks current work.
    expect(UsageScorer::score($staleEvents, $now))->toBeLessThan(0.05);
});

function seedCollectionsSearchFixture(Team $team, string $artifactId, string $title): void
{
    /** @var FakeArtifactDirectory $directory */
    $directory = app(ArtifactDirectory::class);
    $directory->seedArtifact([
        'id' => $artifactId,
        'org_id' => $team->slug,
        'user_id' => 1,
        'title' => $title,
        'description' => 'A test artifact.',
        'content_hash' => md5($artifactId),
        'created_at' => now()->subDay()->toIso8601String(),
        'revoked_at' => null,
        'provenance' => ['agent' => 'cursor', 'repo_url' => 'https://github.com/acme/misc', 'commit_sha' => 'a'],
    ]);

    /** @var FakeVectorIndex $vectorIndex */
    $vectorIndex = app(VectorIndexContract::class);
    $vector = app(EmbeddingsContract::class)->embed(['shared query text'])[0];
    $vectorIndex->upsertChunks($team->slug, $artifactId, [
        new VectorChunk(
            text: 'shared query text',
            vector: $vector,
            artifactId: $artifactId,
            orgId: $team->slug,
            createdAt: now()->subDay()->toIso8601String(),
            agent: 'cursor',
            repoUrl: 'https://github.com/acme/misc',
            commitSha: 'a',
        ),
    ]);
}

test('canonical_collection_boosts_ranking', function () {
    $team = Team::factory()->create(['slug' => 'canon-org']);
    $admin = memberOfTeam($team, TeamRole::Admin);
    seedCollectionsSearchFixture($team, 'canonical-artifact', 'Canonical Doc');
    seedCollectionsSearchFixture($team, 'other-artifact', 'Other Doc');

    $collectionService = app(CollectionService::class);
    $collection = $collectionService->create($team, $admin, 'Reporting Formats');
    $collectionService->addArtifact($collection, 'canonical-artifact');
    $collectionService->pinCanonical($collection, $admin);

    $results = app(SearchService::class)->search($team, 'shared query text', [], limit: 10, actor: 'tester');

    expect($results)->not->toBeEmpty();
    expect($results[0]->id)->toBe('canonical-artifact');
});

test('collection_scoped_search_excludes_others', function () {
    $team = Team::factory()->create(['slug' => 'scope-org']);
    $admin = memberOfTeam($team, TeamRole::Admin);
    seedCollectionsSearchFixture($team, 'in-collection', 'In Collection');
    seedCollectionsSearchFixture($team, 'out-of-collection', 'Out of Collection');

    $collectionService = app(CollectionService::class);
    $collection = $collectionService->create($team, $admin, 'reporting-formats');
    $collectionService->addArtifact($collection, 'in-collection');

    $results = app(SearchService::class)->search(
        $team,
        'shared query text',
        ['collection' => 'reporting-formats'],
        limit: 10,
        actor: 'tester',
    );

    expect($results)->toHaveCount(1);
    expect($results[0]->id)->toBe('in-collection');
});

test('collection_invisible_across_orgs', function () {
    $orgA = Team::factory()->create(['slug' => 'org-a-coll']);
    $orgB = Team::factory()->create(['slug' => 'org-b-coll']);
    $adminA = memberOfTeam($orgA, TeamRole::Admin);

    app(CollectionService::class)->create($orgA, $adminA, 'org-a-only-collection');

    expect(Collection::query()->where('team_id', $orgB->id)->where('name', 'org-a-only-collection')->exists())->toBeFalse();
});

test('viewer_cannot_pin_canonical', function () {
    $team = Team::factory()->create();
    $admin = memberOfTeam($team, TeamRole::Admin);
    $viewer = memberOfTeam($team, TeamRole::Viewer);

    $collection = app(CollectionService::class)->create($team, $admin, 'Runbooks');

    expect(fn () => app(CollectionService::class)->pinCanonical($collection, $viewer))
        ->toThrow(AuthorizationException::class);

    expect($collection->fresh()->canonical)->toBeFalse();
});

test('ranking_is_useful_with_no_collections', function () {
    // Zero collections defined anywhere — ranking must still work purely
    // from semantic + usage signals (DoD: "automatic signal alone must be
    // useful, since most orgs will never create one").
    $team = Team::factory()->create(['slug' => 'no-collections-org']);
    seedCollectionsSearchFixture($team, 'only-artifact', 'Only Doc');

    $results = app(SearchService::class)->search($team, 'shared query text', [], limit: 10, actor: 'tester');

    expect($results)->not->toBeEmpty();
    expect($results[0]->id)->toBe('only-artifact');
});
