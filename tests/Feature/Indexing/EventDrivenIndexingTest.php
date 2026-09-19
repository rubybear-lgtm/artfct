<?php

use App\Contracts\ArtifactContentSource;
use App\Enums\TeamRole;
use App\Jobs\IndexArtifactJob;
use App\Models\ArtifactIndexEntry;
use App\Models\ArtifactIndexingFailure;
use App\Models\Team;
use App\Services\Artifacts\FakeArtifactContentSource;
use App\Services\Indexing\FakeRenderer;
use App\Services\Indexing\FakeVectorIndex;
use App\Services\Indexing\IndexingService;
use App\Services\Indexing\RendererContract;
use App\Services\Indexing\RenderTimeoutException;
use App\Services\Indexing\VectorIndexContract;
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
    return postWorkerEvent([
        'id' => $eventId ?? (string) Str::uuid(),
        'type' => 'artifact.created',
        'org_id' => $org,
        'data' => ['artifact_id' => $artifactId, 'tier' => 'secure'],
    ]);
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

test('event_for_foreign_org_artifact_dispatches_nothing', function () {
    Queue::fake();
    Team::factory()->create(['slug' => 'acme']);
    Team::factory()->create(['slug' => 'other']);
    seedContent('other', 'art-foreign');

    artifactCreatedEvent('acme', 'art-foreign')->assertStatus(202);

    Queue::assertNothingPushed();
});

test('indexing_disabled_records_event_without_dispatch', function () {
    Queue::fake();
    config(['indexing.enabled' => false]);
    Team::factory()->create(['slug' => 'acme']);
    seedContent('acme', 'art-1');
    $eventId = (string) Str::uuid();

    artifactCreatedEvent('acme', 'art-1', $eventId)->assertStatus(202);

    Queue::assertNothingPushed();
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

    Queue::assertNothingPushed();
});
