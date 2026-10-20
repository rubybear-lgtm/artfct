<?php

use App\Contracts\ArtifactDirectory;
use App\Enums\AuditEventType;
use App\Enums\TeamRole;
use App\Models\ArtifactIndexEntry;
use App\Models\AuditEvent;
use App\Models\Team;
use App\Services\Artifacts\HttpArtifactDirectory;
use App\Services\Indexing\IndexingService;
use App\Services\Indexing\VectorIndexContract;
use Illuminate\Support\Facades\Http;

/**
 * Binds the real Worker-backed directory and answers every Worker call with
 * the given status, so the console's Worker-confirmation path is exercised.
 */
function workerAnswersOrgCall(int $status): void
{
    configureSigning(testSigningKey());
    config(['services.worker.base_url' => 'https://worker.test']);
    app()->instance(ArtifactDirectory::class, new HttpArtifactDirectory('https://worker.test'));
    Http::fake(['worker.test/*' => Http::response([], $status)]);
}

test('a_confirmed_revoke_purges_the_index_and_writes_the_audit_event', function () {
    $team = Team::factory()->create(['slug' => 'test-org']);
    $admin = memberOfTeam($team, TeamRole::Admin);
    $artifactId = '1234567890';
    $html = '<html><body><p>'.str_repeat('A confirmed revoke must leave the search index. ', 3).'</p></body></html>';
    app(IndexingService::class)->indexArtifact($team, $artifactId, $html, []);
    workerAnswersOrgCall(200);

    test()->actingAs($admin)
        ->patch("/settings/teams/{$team->slug}/console/artifacts/{$artifactId}/revoke")
        ->assertRedirect()
        ->assertSessionHas('inertia.flash_data.toast', [
            'type' => 'success',
            'message' => 'Artifact revoked.',
        ]);

    expect(app(VectorIndexContract::class)->artifactHasVectors($team->slug, $artifactId))->toBeFalse()
        ->and(ArtifactIndexEntry::query()->where('artifact_id', $artifactId)->exists())->toBeFalse()
        ->and(AuditEvent::query()->where('event_type', AuditEventType::ArtifactRevoked)->count())->toBe(1);
});

test('a_worker_error_on_revoke_keeps_the_index_and_the_audit_log_and_says_so', function (int $status) {
    $team = Team::factory()->create(['slug' => 'test-org']);
    $admin = memberOfTeam($team, TeamRole::Admin);
    $artifactId = '1234567890';
    $html = '<html><body><p>'.str_repeat('An unconfirmed revoke must not touch the index. ', 3).'</p></body></html>';
    app(IndexingService::class)->indexArtifact($team, $artifactId, $html, []);
    workerAnswersOrgCall($status);

    test()->actingAs($admin)
        ->patch("/settings/teams/{$team->slug}/console/artifacts/{$artifactId}/revoke")
        ->assertRedirect()
        ->assertSessionHas('inertia.flash_data.toast', [
            'type' => 'error',
            'message' => "Couldn't revoke the artifact right now. It is still live, so try again in a minute.",
        ]);

    expect(app(VectorIndexContract::class)->artifactHasVectors($team->slug, $artifactId))->toBeTrue()
        ->and(ArtifactIndexEntry::query()->where('artifact_id', $artifactId)->exists())->toBeTrue()
        ->and(AuditEvent::query()->where('event_type', AuditEventType::ArtifactRevoked)->exists())->toBeFalse();
})->with([500, 401]);

test('a_worker_error_on_export_is_never_offered_as_a_download', function (int $status) {
    $team = Team::factory()->create(['slug' => 'test-org']);
    $admin = memberOfTeam($team, TeamRole::Admin);
    workerAnswersOrgCall($status);

    test()->actingAs($admin)
        ->get("/settings/teams/{$team->slug}/console/export")
        ->assertRedirect()
        ->assertSessionHas('inertia.flash_data.toast', [
            'type' => 'error',
            'message' => "Couldn't prepare the export right now. Try again in a minute.",
        ]);

    expect(AuditEvent::query()->where('event_type', AuditEventType::ExportPerformed)->exists())->toBeFalse();
})->with([500, 401]);
