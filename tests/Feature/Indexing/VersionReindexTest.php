<?php

use App\Contracts\ArtifactContentSource;
use App\Jobs\IndexArtifactJob;
use App\Jobs\ProcessWorkerEvent;
use App\Models\ArtifactIndexEntry;
use App\Models\Team;
use App\Services\Artifacts\FakeArtifactContentSource;
use App\Services\Indexing\FakeVectorIndex;
use App\Services\Indexing\IndexingService;
use App\Services\Indexing\VectorIndexContract;
use App\Services\WorkerEvents\WorkerEventHandlers;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    config([
        'services.worker_events.secret' => 'test-secret',
        'indexing.enabled' => true,
    ]);
});

function versionReindexSeed(string $org, string $artifactId, string $html, int $version): void
{
    /** @var FakeArtifactContentSource $source */
    $source = app(ArtifactContentSource::class);
    $source->seed($org, $artifactId, $html, ['agent' => 'claude-code', 'repo_url' => null, 'commit_sha' => null], version: $version);
}

function versionReindexEvent(string $type, string $org, string $artifactId): TestResponse
{
    $queuedBeforeRequest = Queue::pushed(ProcessWorkerEvent::class)->count();

    $response = postWorkerEvent([
        'id' => (string) Str::uuid(),
        'type' => $type,
        'org_id' => $org,
        'data' => ['artifact_id' => $artifactId, 'tier' => 'secure'],
    ]);

    Queue::pushed(ProcessWorkerEvent::class)->slice($queuedBeforeRequest)->each(
        fn (ProcessWorkerEvent $job): mixed => $job->handle(app(WorkerEventHandlers::class)),
    );

    return $response;
}

test('a_new_version_replaces_the_indexed_entry_and_records_the_fetched_version', function () {
    Queue::fake();
    $team = Team::factory()->create(['slug' => 'acme']);
    $artifactId = 'art-1';

    versionReindexSeed('acme', $artifactId, '<html><body><p>alpha alpha alpha alpha alpha alpha alpha alpha alpha alpha</p></body></html>', 1);
    versionReindexEvent('artifact.created', 'acme', $artifactId)->assertStatus(202);
    Queue::pushed(IndexArtifactJob::class)->first()->handle(app(IndexingService::class));

    versionReindexSeed('acme', $artifactId, '<html><body><p>beta beta beta beta beta beta beta beta beta beta</p></body></html>', 2);
    versionReindexEvent('artifact.version_created', 'acme', $artifactId)->assertStatus(202);
    Queue::pushed(IndexArtifactJob::class)->last()->handle(app(IndexingService::class));

    $entries = ArtifactIndexEntry::query()->where('team_id', $team->id)->where('artifact_id', $artifactId)->get();

    expect($entries)->toHaveCount(1);
    $entry = $entries->first();
    expect($entry->version)->toBe(2)
        ->and($entry->extracted_text)->toContain('beta')
        ->and($entry->extracted_text)->not->toContain('alpha');
});

test('a_new_version_leaves_only_the_new_versions_vectors_for_the_artifact', function () {
    Queue::fake();
    $team = Team::factory()->create(['slug' => 'acme']);
    $artifactId = 'art-1';

    versionReindexSeed('acme', $artifactId, '<html><body><p>alpha alpha alpha alpha alpha alpha alpha alpha alpha alpha</p></body></html>', 1);
    versionReindexEvent('artifact.created', 'acme', $artifactId)->assertStatus(202);
    Queue::pushed(IndexArtifactJob::class)->first()->handle(app(IndexingService::class));

    /** @var FakeVectorIndex $index */
    $index = app(VectorIndexContract::class);
    expect($index->allVectorsForOrg($team->slug))->not->toBeEmpty();

    versionReindexSeed('acme', $artifactId, '<html><body><p>beta beta beta beta beta beta beta beta beta beta</p></body></html>', 2);
    versionReindexEvent('artifact.version_created', 'acme', $artifactId)->assertStatus(202);
    Queue::pushed(IndexArtifactJob::class)->last()->handle(app(IndexingService::class));

    $chunks = $index->allVectorsForOrg($team->slug);

    expect($chunks)->not->toBeEmpty();
    foreach ($chunks as $chunk) {
        expect($chunk->text)->toContain('beta')
            ->and($chunk->text)->not->toContain('alpha')
            ->and($chunk->artifactId)->toBe($artifactId);
    }
});

test('artifact_created_still_skips_an_already_indexed_artifact', function () {
    Queue::fake();
    $team = Team::factory()->create(['slug' => 'acme']);
    versionReindexSeed('acme', 'art-1', '<html><body><p>alpha alpha alpha alpha alpha alpha alpha alpha alpha alpha</p></body></html>', 1);
    ArtifactIndexEntry::query()->create([
        'team_id' => $team->id,
        'artifact_id' => 'art-1',
        'version' => 7,
        'rendered' => false,
        'extracted_text' => 'already here',
        'headings' => [],
        'extracted_at' => now(),
    ]);

    versionReindexEvent('artifact.created', 'acme', 'art-1')->assertStatus(202);

    Queue::assertNotPushed(IndexArtifactJob::class);
    expect(ArtifactIndexEntry::query()->where('artifact_id', 'art-1')->value('version'))->toBe(7);
});

test('a_version_event_for_an_artifact_in_another_org_indexes_nothing', function () {
    Queue::fake();
    Team::factory()->create(['slug' => 'acme']);
    Team::factory()->create(['slug' => 'other']);
    versionReindexSeed('other', 'art-foreign', '<html><body><p>beta beta beta beta beta beta beta beta beta beta</p></body></html>', 2);

    versionReindexEvent('artifact.version_created', 'acme', 'art-foreign')->assertStatus(202);

    Queue::assertNotPushed(IndexArtifactJob::class);
    expect(ArtifactIndexEntry::query()->count())->toBe(0);
});

test('artifact_created_stores_the_version_from_the_content_read', function () {
    Queue::fake();
    $team = Team::factory()->create(['slug' => 'acme']);
    versionReindexSeed('acme', 'art-1', '<html><body><p>alpha alpha alpha alpha alpha alpha alpha alpha alpha alpha</p></body></html>', 1);

    versionReindexEvent('artifact.created', 'acme', 'art-1')->assertStatus(202);
    Queue::pushed(IndexArtifactJob::class)->first()->handle(app(IndexingService::class));

    expect(ArtifactIndexEntry::query()->where('team_id', $team->id)->where('artifact_id', 'art-1')->value('version'))->toBe(1);
});
