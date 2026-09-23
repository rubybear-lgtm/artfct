<?php

use App\Enums\TeamRole;
use App\Jobs\IndexArtifactJob;
use App\Models\ArtifactIndexEntry;
use App\Models\ArtifactIndexingFailure;
use App\Models\Team;
use App\Services\Indexing\Chunker;
use App\Services\Indexing\EmbeddingsContract;
use App\Services\Indexing\ExtractionHeuristics;
use App\Services\Indexing\FakeEmbeddings;
use App\Services\Indexing\FakeRenderer;
use App\Services\Indexing\FakeVectorIndex;
use App\Services\Indexing\IndexingService;
use App\Services\Indexing\RendererContract;
use App\Services\Indexing\RenderResult;
use App\Services\Indexing\RenderTimeoutException;
use App\Services\Indexing\VectorIndexContract;
use Illuminate\Support\Facades\Queue;

test('js_heavy_artifact_indexes_post_hydration_text', function () {
    $team = Team::factory()->create();
    $html = '<div id="root"></div><script src="/app.js"></script>';

    /** @var FakeRenderer $renderer */
    $renderer = app(RendererContract::class);
    $renderer->seedRender($html, new RenderResult(
        text: 'Q3 revenue grew 40% year over year.',
        title: 'Dashboard',
        headings: ['Revenue'],
    ));

    $entry = app(IndexingService::class)->indexArtifact($team, 'artifact-js', $html, []);

    expect($entry->rendered)->toBeTrue();
    expect($entry->extracted_text)->toContain('Q3 revenue grew 40%');
    expect($renderer->callCount)->toBe(1);
});

test('static_artifact_indexes_without_render', function () {
    $team = Team::factory()->create();
    $html = '<html><body><h1>Quarterly Report</h1><p>'.str_repeat('Revenue was strong across every region this quarter. ', 3).'</p></body></html>';

    /** @var FakeRenderer $renderer */
    $renderer = app(RendererContract::class);

    $entry = app(IndexingService::class)->indexArtifact($team, 'artifact-static', $html, []);

    expect($entry->rendered)->toBeFalse();
    expect($entry->extracted_text)->toContain('Quarterly Report');
    expect($renderer->callCount)->toBe(0);
});

test('indexing_does_not_block_deploy', function () {
    Queue::fake();

    /** @var FakeRenderer $renderer */
    $renderer = app(RendererContract::class);

    IndexArtifactJob::dispatch(1, 'artifact-1', '<div id="root"></div>', []);

    Queue::assertPushed(IndexArtifactJob::class);
    // The job was queued, not executed inline — the renderer was never
    // touched by the dispatch call itself.
    expect($renderer->callCount)->toBe(0);
});

test('render_timeout_retries_then_dead_letters', function () {
    $team = Team::factory()->create();
    $html = '<div id="root"></div>';

    /** @var FakeRenderer $renderer */
    $renderer = app(RendererContract::class);
    $renderer->timeoutOnNextRender($html);

    $job = new IndexArtifactJob($team->id, 'artifact-timeout', $html, []);

    expect(fn () => $job->handle(app(IndexingService::class)))
        ->toThrow(RenderTimeoutException::class);

    // Simulates the queue worker exhausting $tries and invoking the
    // failure hook — this is what dead-letters the attempt.
    $job->failed(new RenderTimeoutException('Render timed out.'));

    $failure = ArtifactIndexingFailure::query()->where('artifact_id', 'artifact-timeout')->firstOrFail();
    expect($failure->reason)->toContain('Render timed out');
    expect($job->backoff())->toBe([10, 30, 60]);
    expect($job->tries)->toBe(3);
});

test('dead_lettered_artifact_still_serves', function () {
    ArtifactIndexingFailure::query()->create([
        'team_id' => Team::factory()->create()->id,
        'artifact_id' => '1234567890',
        'attempts' => 3,
        'reason' => 'Render timed out.',
        'failed_at' => now(),
    ]);

    $team = Team::factory()->create(['slug' => 'test-org']);
    $admin = memberOfTeam($team, TeamRole::Admin);

    // The console (the serving/listing path) is entirely unaffected by a
    // dead-lettered indexing attempt — different table, different service.
    $response = test()->actingAs($admin)->get("/settings/teams/{$team->slug}/console");
    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->where('artifacts.0.id', '1234567890'));
});

test('extracted_text_persisted_for_reembedding', function () {
    $team = Team::factory()->create();
    $html = '<html><body><p>'.str_repeat('Persisted extraction content for later reuse. ', 3).'</p></body></html>';

    /** @var FakeRenderer $renderer */
    $renderer = app(RendererContract::class);
    /** @var FakeEmbeddings $embeddings */
    $embeddings = app(EmbeddingsContract::class);

    app(IndexingService::class)->indexArtifact($team, 'artifact-reembed', $html, ['agent' => 'cursor']);
    expect(ArtifactIndexEntry::query()->where('artifact_id', 'artifact-reembed')->exists())->toBeTrue();

    $embedCallsBeforeReembed = $embeddings->callCount;
    $renderCallsBeforeReembed = $renderer->callCount;

    app(IndexingService::class)->reembed($team, 'artifact-reembed', ['agent' => 'cursor']);

    expect($embeddings->callCount)->toBeGreaterThan($embedCallsBeforeReembed);
    expect($renderer->callCount)->toBe($renderCallsBeforeReembed); // no re-render
});

test('chunks_carry_provenance_metadata', function () {
    $team = Team::factory()->create(['slug' => 'prov-org']);
    $html = '<html><body><p>'.str_repeat('Chunk provenance metadata content. ', 5).'</p></body></html>';

    app(IndexingService::class)->indexArtifact($team, 'artifact-prov', $html, [
        'agent' => 'claude-code',
        'repo_url' => 'https://github.com/acme/dashboard',
        'commit_sha' => 'abc123',
    ]);

    /** @var FakeVectorIndex $vectorIndex */
    $vectorIndex = app(VectorIndexContract::class);
    $chunks = $vectorIndex->allVectorsForOrg('prov-org');

    expect($chunks)->not->toBeEmpty();
    foreach ($chunks as $chunk) {
        expect($chunk->orgId)->toBe('prov-org');
        expect($chunk->artifactId)->toBe('artifact-prov');
        expect($chunk->agent)->toBe('claude-code');
        expect($chunk->repoUrl)->toBe('https://github.com/acme/dashboard');
        expect($chunk->commitSha)->toBe('abc123');
        expect($chunk->createdAt)->not->toBeEmpty();
    }
});

test('tenant_index_contains_no_foreign_vectors', function () {
    $teamA = Team::factory()->create(['slug' => 'org-a']);
    $teamB = Team::factory()->create(['slug' => 'org-b']);
    $html = '<html><body><p>'.str_repeat('Tenant isolation content for the vector index. ', 5).'</p></body></html>';

    app(IndexingService::class)->indexArtifact($teamA, 'artifact-a', $html, []);
    app(IndexingService::class)->indexArtifact($teamB, 'artifact-b', $html, []);

    /** @var FakeVectorIndex $vectorIndex */
    $vectorIndex = app(VectorIndexContract::class);

    $orgAVectors = $vectorIndex->allVectorsForOrg('org-a');
    expect($orgAVectors)->not->toBeEmpty();
    foreach ($orgAVectors as $chunk) {
        expect($chunk->orgId)->toBe('org-a');
        expect($chunk->artifactId)->not->toBe('artifact-b');
    }
});

test('artifact_deletion_removes_vectors', function () {
    $team = Team::factory()->create(['slug' => 'delete-org']);
    $html = '<html><body><p>'.str_repeat('Content that will be deleted from the index. ', 5).'</p></body></html>';

    app(IndexingService::class)->indexArtifact($team, 'artifact-delete', $html, []);

    /** @var FakeVectorIndex $vectorIndex */
    $vectorIndex = app(VectorIndexContract::class);
    expect($vectorIndex->artifactHasVectors('delete-org', 'artifact-delete'))->toBeTrue();

    app(IndexingService::class)->removeFromIndex($team, 'artifact-delete');

    expect($vectorIndex->artifactHasVectors('delete-org', 'artifact-delete'))->toBeFalse();
});

test('indexing_outage_degrades_search_not_serving', function () {
    // No index entries exist at all for this org — simulating a total
    // indexing outage — yet the console (serving/listing) still works,
    // because it reads from ArtifactDirectory, never from the index.
    $team = Team::factory()->create(['slug' => 'test-org']);
    $admin = memberOfTeam($team, TeamRole::Admin);

    expect(ArtifactIndexEntry::query()->where('team_id', $team->id)->exists())->toBeFalse();

    $response = test()->actingAs($admin)->get("/settings/teams/{$team->slug}/console");
    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->where('artifacts.0.id', '1234567890'));
});

// --- Pure heuristics --------------------------------------------------

test('extraction_heuristics_needs_render_for_hydration_shell', function () {
    expect(ExtractionHeuristics::needsRender('<div id="root"></div><script src="/app.js"></script>'))->toBeTrue();
});

test('extraction_heuristics_skips_render_for_real_content', function () {
    $html = '<html><body><h1>Report</h1><p>'.str_repeat('Real static content. ', 5).'</p></body></html>';
    expect(ExtractionHeuristics::needsRender($html))->toBeFalse();
});

test('chunker_splits_long_text_with_overlap', function () {
    $text = str_repeat('a', 1000);
    $chunks = Chunker::chunk($text, chunkSize: 400, overlap: 50);

    expect(count($chunks))->toBeGreaterThan(1);
    foreach ($chunks as $chunk) {
        expect(mb_strlen($chunk))->toBeLessThanOrEqual(400);
    }
});

test('chunker_returns_single_chunk_for_short_text', function () {
    expect(Chunker::chunk('short text'))->toBe(['short text']);
});
