<?php

use App\Contracts\ArtifactContentSource;
use App\Contracts\ArtifactDirectory;
use App\Enums\TeamRole;
use App\Jobs\IndexArtifactJob;
use App\Jobs\ProcessWorkerEvent;
use App\Models\ArtifactIndexEntry;
use App\Models\ArtifactIndexingFailure;
use App\Models\Team;
use App\Services\Artifacts\FakeArtifactContentSource;
use App\Services\Artifacts\FakeArtifactDirectory;
use App\Services\Indexing\FakeRenderer;
use App\Services\Indexing\FakeVectorIndex;
use App\Services\Indexing\IndexingService;
use App\Services\Indexing\RendererContract;
use App\Services\Indexing\RenderResult;
use App\Services\Indexing\RenderTimeoutException;
use App\Services\Indexing\VectorChunk;
use App\Services\Indexing\VectorIndexContract;
use App\Services\WorkerEvents\WorkerEventHandlers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    config([
        'services.worker_events.secret' => 'test-secret',
        'indexing.enabled' => true,
    ]);
});

function artifactCreatedEvent(string $org, string $artifactId, ?string $eventId = null): TestResponse
{
    $queuedBeforeRequest = Queue::pushed(ProcessWorkerEvent::class)->count();
    $response = postWorkerEvent([
        'id' => $eventId ?? (string) Str::uuid(),
        'type' => 'artifact.created',
        'org_id' => $org,
        'data' => ['artifact_id' => $artifactId, 'tier' => 'secure'],
    ]);

    Queue::pushed(ProcessWorkerEvent::class)->slice($queuedBeforeRequest)->each(
        fn (ProcessWorkerEvent $job): mixed => $job->handle(app(WorkerEventHandlers::class)),
    );

    return $response;
}

function seedContent(string $org, string $artifactId, string $html = '<html><body><h1>Quarterly Report</h1><p>Revenue was strong across every region this quarter. Revenue was strong across every region this quarter.</p></body></html>', array $provenance = ['agent' => 'claude-code', 'repo_url' => 'github.com/acme/billing', 'commit_sha' => 'abc123']): void
{
    /** @var FakeArtifactContentSource $source */
    $source = app(ArtifactContentSource::class);
    $source->seed($org, $artifactId, $html, $provenance);
}

test('artifact_created_event_dispatches_index_job', function () {
    Queue::fake();
    Team::factory()->create(['slug' => 'acme']);
    seedContent('acme', 'art-1');

    artifactCreatedEvent('acme', 'art-1')->assertStatus(202);

    Queue::assertPushedOn('indexing', IndexArtifactJob::class, fn (IndexArtifactJob $job) => $job->artifactId === 'art-1' && $job->provenance['agent'] === 'claude-code');
});

test('duplicate_event_dispatches_once', function () {
    Queue::fake();
    Team::factory()->create(['slug' => 'acme']);
    seedContent('acme', 'art-1');
    $eventId = (string) Str::uuid();

    artifactCreatedEvent('acme', 'art-1', $eventId)->assertStatus(202);
    artifactCreatedEvent('acme', 'art-1', $eventId)->assertOk();

    Queue::assertPushed(IndexArtifactJob::class, 1);
});

test('artifact_deleted_event_removes_only_its_org_index_and_is_idempotent', function () {
    Queue::fake();
    $teamA = Team::factory()->create(['slug' => 'deleted-a']);
    $teamB = Team::factory()->create(['slug' => 'deleted-b']);
    $artifactId = 'shared-artifact';
    $index = app(VectorIndexContract::class);

    foreach ([$teamA, $teamB] as $team) {
        ArtifactIndexEntry::query()->create([
            'team_id' => $team->id,
            'artifact_id' => $artifactId,
            'rendered' => false,
            'extracted_text' => 'Shared searchable text',
            'headings' => [],
            'extracted_at' => now(),
        ]);
        $index->upsertChunks($team->slug, $artifactId, [
            new VectorChunk('Shared searchable text', [1.0], $artifactId, $team->slug, now()->toIso8601String(), null, null, null),
        ]);
    }

    $eventId = (string) Str::uuid();
    $event = [
        'id' => $eventId,
        'type' => 'artifact.deleted',
        'org_id' => $teamA->slug,
        'data' => ['artifact_id' => $artifactId],
    ];
    postWorkerEvent($event)->assertStatus(202);
    postWorkerEvent($event)->assertOk();
    Queue::assertPushed(ProcessWorkerEvent::class, 1);
    Queue::pushed(ProcessWorkerEvent::class)->first()->handle(app(WorkerEventHandlers::class));

    expect(ArtifactIndexEntry::query()->where('team_id', $teamA->id)->where('artifact_id', $artifactId)->exists())->toBeFalse()
        ->and(ArtifactIndexEntry::query()->where('team_id', $teamB->id)->where('artifact_id', $artifactId)->exists())->toBeTrue()
        ->and($index->allVectorsForOrg($teamA->slug))->toBeEmpty()
        ->and($index->allVectorsForOrg($teamB->slug))->toHaveCount(1);
});

test('event_for_foreign_org_artifact_dispatches_nothing', function () {
    Queue::fake();
    Team::factory()->create(['slug' => 'acme']);
    Team::factory()->create(['slug' => 'other']);
    seedContent('other', 'art-foreign');

    artifactCreatedEvent('acme', 'art-foreign')->assertStatus(202);

    Queue::assertNotPushed(IndexArtifactJob::class);
});

test('indexing_disabled_records_event_without_dispatch', function () {
    Queue::fake();
    config(['indexing.enabled' => false]);
    Team::factory()->create(['slug' => 'acme']);
    seedContent('acme', 'art-1');
    $eventId = (string) Str::uuid();

    artifactCreatedEvent('acme', 'art-1', $eventId)->assertStatus(202);

    Queue::assertNotPushed(IndexArtifactJob::class);
    expect(DB::table('worker_events_received')->where('event_id', $eventId)->count())->toBe(1)
        ->and(ArtifactIndexEntry::query()->count())->toBe(0)
        ->and(ArtifactIndexingFailure::query()->count())->toBe(0);
});

test('dispatched_job_failure_dead_letters_and_artifact_still_serves', function () {
    $team = Team::factory()->create(['slug' => 'acme']);
    $html = '<div id="root"></div>';
    seedContent('acme', 'art-js', $html);

    /** @var FakeRenderer $renderer */
    $renderer = app(RendererContract::class);
    $renderer->timeoutOnNextRender($html);

    Queue::fake();
    artifactCreatedEvent('acme', 'art-js')->assertStatus(202);
    $pushed = Queue::pushed(IndexArtifactJob::class)->first();

    expect(fn () => $pushed->handle(app(IndexingService::class)))->toThrow(RenderTimeoutException::class);
    $pushed->failed(new RenderTimeoutException('Render timed out.'));

    expect(ArtifactIndexingFailure::query()->where('team_id', $team->id)->where('artifact_id', 'art-js')->exists())->toBeTrue();

    $admin = memberOfTeam($team, TeamRole::Admin);
    test()->actingAs($admin)->get("/settings/teams/{$team->slug}/console")->assertOk();
});

test('event_driven_chunks_carry_provenance', function () {
    $team = Team::factory()->create(['slug' => 'acme']);
    seedContent('acme', 'art-1');

    Queue::fake();
    artifactCreatedEvent('acme', 'art-1')->assertStatus(202);
    Queue::pushed(IndexArtifactJob::class)->first()->handle(app(IndexingService::class));

    /** @var FakeVectorIndex $index */
    $index = app(VectorIndexContract::class);
    $chunks = $index->allVectorsForOrg($team->slug);

    expect($chunks)->not->toBeEmpty();
    foreach ($chunks as $chunk) {
        expect($chunk->orgId)->toBe('acme')
            ->and($chunk->agent)->toBe('claude-code')
            ->and($chunk->repoUrl)->toBe('github.com/acme/billing')
            ->and($chunk->commitSha)->toBe('abc123');
    }
});

test('hydrated_artifact_becomes_searchable_after_its_event_and_indexing_job', function () {
    $team = Team::factory()->create(['slug' => 'hydrated-org']);
    $artifactId = 'hydrated-dashboard';
    $html = '<html><body><div id="root"></div><script src="app.js"></script></body></html>';
    $hydratedText = 'Hydrated React revenue dashboard';
    seedContent($team->slug, $artifactId, $html);

    /** @var FakeRenderer $renderer */
    $renderer = app(RendererContract::class);
    $renderer->seedRender($html, new RenderResult($hydratedText, 'Revenue Dashboard', ['Revenue']));

    /** @var FakeArtifactDirectory $directory */
    $directory = app(ArtifactDirectory::class);
    $directory->seedArtifact([
        'id' => $artifactId,
        'org_id' => $team->slug,
        'user_id' => 1,
        'title' => 'Revenue Dashboard',
        'description' => 'A hydrated dashboard.',
        'content_hash' => md5($artifactId),
        'created_at' => now()->toIso8601String(),
        'revoked_at' => null,
        'provenance' => ['agent' => 'claude-code', 'repo_url' => null, 'commit_sha' => null],
    ]);

    Queue::fake();
    artifactCreatedEvent($team->slug, $artifactId)->assertStatus(202);
    Queue::assertPushedOn('indexing', IndexArtifactJob::class);
    Queue::pushed(IndexArtifactJob::class)->first()->handle(app(IndexingService::class));

    expect($renderer->callCount)->toBe(1)
        ->and(ArtifactIndexEntry::query()->where('team_id', $team->id)->where('artifact_id', $artifactId)->value('extracted_text'))->toBe($hydratedText);

    $this->withToken(remoteMcpToken($team))
        ->postJson('/api/search', ['query' => $hydratedText])
        ->assertOk()
        ->assertJsonPath('results.0.id', $artifactId)
        ->assertJsonPath('results.0.title', 'Revenue Dashboard');
});

test('already_indexed_artifact_is_not_redispatched', function () {
    Queue::fake();
    $team = Team::factory()->create(['slug' => 'acme']);
    seedContent('acme', 'art-1');
    ArtifactIndexEntry::query()->create([
        'team_id' => $team->id,
        'artifact_id' => 'art-1',
        'rendered' => false,
        'extracted_text' => 'already here',
        'headings' => [],
        'extracted_at' => now(),
    ]);

    artifactCreatedEvent('acme', 'art-1')->assertStatus(202);

    Queue::assertNotPushed(IndexArtifactJob::class);
});

test('transient_content_read_failure_retries_the_event_instead_of_losing_the_artifact', function () {
    Team::factory()->create(['slug' => 'acme']);
    $source = Mockery::mock(ArtifactContentSource::class);
    $source->shouldReceive('fetch')->once()->with('acme', 'art-retry')->andThrow(new RuntimeException('Temporary Worker outage'));
    app()->instance(ArtifactContentSource::class, $source);
    $event = [
        'id' => (string) Str::uuid(), 'type' => 'artifact.created', 'org_id' => 'acme',
        'occurred_at' => now()->toIso8601String(), 'data' => ['artifact_id' => 'art-retry'],
    ];
    $job = new ProcessWorkerEvent($event);
    expect(fn () => $job->handle(app(WorkerEventHandlers::class)))
        ->toThrow(RuntimeException::class, 'Artifact content read failed; event will retry.');
    expect($job->backoff())->toBe([5, 30, 120]);
    expect($job->tries)->toBe(3);
    expect(ArtifactIndexEntry::query()->count())->toBe(0);
});
