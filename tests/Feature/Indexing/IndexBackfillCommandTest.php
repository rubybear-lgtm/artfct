<?php

use App\Contracts\ArtifactContentSource;
use App\Contracts\ArtifactDirectory;
use App\Jobs\IndexArtifactJob;
use App\Models\ArtifactIndexEntry;
use App\Models\Team;
use App\Services\Artifacts\FakeArtifactContentSource;
use App\Services\Artifacts\FakeArtifactDirectory;
use Illuminate\Support\Facades\Bus;

function backfillArtifact(Team $team, string $id, bool $revoked = false): void
{
    /** @var FakeArtifactDirectory $directory */
    $directory = app(ArtifactDirectory::class);
    $directory->seedArtifact([
        'id' => $id, 'org_id' => $team->slug, 'user_id' => 1, 'title' => "Artifact {$id}", 'description' => 'd',
        'content_hash' => md5($id), 'created_at' => now()->subDay()->toIso8601String(),
        'revoked_at' => $revoked ? now()->toIso8601String() : null,
        'provenance' => ['agent' => 'cursor', 'repo_url' => null, 'commit_sha' => null],
    ]);

    /** @var FakeArtifactContentSource $content */
    $content = app(ArtifactContentSource::class);
    $content->seed($team->slug, $id, "<h1>{$id}</h1>");
}

test('backfill_queues_only_live_artifacts_that_are_not_indexed_yet', function () {
    Bus::fake();
    config(['indexing.enabled' => true]);
    $team = Team::factory()->create(['slug' => 'backfill-org']);

    backfillArtifact($team, 'needs-index');
    backfillArtifact($team, 'already-indexed');
    backfillArtifact($team, 'was-revoked', revoked: true);
    ArtifactIndexEntry::factory()->create(['team_id' => $team->id, 'artifact_id' => 'already-indexed']);

    test()->artisan('indexing:backfill', ['org' => 'backfill-org'])
        ->expectsOutputToContain('1 artifact(s) queued')
        ->assertSuccessful();

    Bus::assertDispatchedTimes(IndexArtifactJob::class, 1);
    Bus::assertDispatched(IndexArtifactJob::class, fn (IndexArtifactJob $job): bool => $job->artifactId === 'needs-index');
});

test('backfill_refuses_to_run_while_indexing_is_off', function () {
    Bus::fake();
    config(['indexing.enabled' => false]);
    Team::factory()->create(['slug' => 'backfill-off']);

    test()->artisan('indexing:backfill', ['org' => 'backfill-off'])->assertFailed();

    Bus::assertNothingDispatched();
});

test('backfill_rejects_an_unknown_org', function () {
    config(['indexing.enabled' => true]);

    test()->artisan('indexing:backfill', ['org' => 'no-such-org'])->assertFailed();
});
