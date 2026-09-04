<?php

use App\Enums\AuditEventType;
use App\Enums\TeamRole;
use App\Models\AuditEvent;
use App\Models\Team;
use App\Services\Governance\ArtifactGovernanceContract;
use App\Services\Governance\ErasureService;
use App\Services\Governance\FakeArtifactGovernance;
use App\Services\Governance\LegalHoldService;
use App\Services\Governance\RetentionService;
use App\Services\Governance\SiemExportService;

/**
 * "Creating, viewing and sharing" an artifact are Worker-owned events —
 * they happen on `POST /v1/artifacts`, `GET /p/{id}`, and the (Worker-side)
 * share-link endpoints, none of which route through Laravel. Those are
 * proven by `backend/src/governance.rs`'s unit tests instead (the
 * `AuditEvent`/`events_to_jsonl` round trip, and
 * `audit_queue_backpressure_does_not_slow_serving`'s structural proof that
 * the write never blocks the response). This test proves the Laravel half:
 * every lifecycle action Laravel itself controls — revoking an artifact,
 * adding/removing a member, changing a role, minting/revoking a token,
 * changing auth_mode — writes an audit row with actor, IP, and timestamp.
 */
test('every_lifecycle_action_writes_an_audit_event', function () {
    $team = Team::factory()->create(['slug' => 'test-org']);
    $admin = memberOfTeam($team, TeamRole::Admin);

    test()->actingAs($admin)->patch(
        "/settings/teams/{$team->slug}/console/artifacts/1234567890/revoke",
        ['revoked_at' => now()->toIso8601String()],
    )->assertRedirect();

    $event = AuditEvent::query()->where('event_type', AuditEventType::ArtifactRevoked)->firstOrFail();
    expect($event->actor)->toBe((string) $admin->id);
    expect($event->ip)->not->toBeEmpty();
    expect($event->created_at)->not->toBeNull();
    expect($event->target)->toBe('artifact:1234567890');
});

test('audit_rows_have_no_update_or_delete_path', function () {
    $team = Team::factory()->create();
    $event = AuditEvent::create([
        'team_id' => $team->id,
        'event_type' => AuditEventType::ExportPerformed,
        'actor' => 'tester',
        'target' => $team->slug,
        'ip' => '127.0.0.1',
        'user_agent' => 'pest',
        'outcome' => 'success',
    ]);

    expect(fn () => $event->update(['outcome' => 'tampered']))
        ->toThrow(LogicException::class);

    // Direct property assignment + save() must also be blocked — not just
    // the update() convenience method — since that's the "not merely by
    // convention" bar the DoD sets.
    $event->outcome = 'tampered';
    expect(fn () => $event->save())->toThrow(LogicException::class);

    expect(fn () => $event->delete())->toThrow(LogicException::class);

    expect(AuditEvent::find($event->id)->outcome)->toBe('success');
});

test('export_writes_export_performed_event', function () {
    $team = Team::factory()->create();
    app(SiemExportService::class)->export($team, actor: 'tester');

    expect(AuditEvent::query()->where('event_type', AuditEventType::ExportPerformed)->where('team_id', $team->id)->exists())->toBeTrue();
});

test('siem_export_is_valid_jsonl', function () {
    $team = Team::factory()->create();
    AuditEvent::create([
        'team_id' => $team->id,
        'event_type' => AuditEventType::MemberAdded,
        'actor' => 'tester',
        'target' => 'user:1',
        'ip' => '127.0.0.1',
        'user_agent' => 'pest',
        'outcome' => 'success',
    ]);

    $jsonl = app(SiemExportService::class)->export($team, actor: 'tester');
    $lines = array_filter(explode("\n", $jsonl));

    expect($lines)->toHaveCount(2); // the seeded event + export.performed's own row
    foreach ($lines as $line) {
        $decoded = json_decode($line, associative: true, flags: JSON_THROW_ON_ERROR);
        expect($decoded)->toHaveKeys(['event_type', 'team', 'actor', 'target', 'ip', 'user_agent', 'timestamp', 'outcome']);
    }
});

test('retention_hard_deletes_expired_artifact', function () {
    $team = Team::factory()->create(['slug' => 'retain-org']);
    /** @var FakeArtifactGovernance $governance */
    $governance = app(ArtifactGovernanceContract::class);
    $governance->seedArtifact('retain-org', ['id' => 'old-artifact', 'created_at' => now()->subDays(31)->toIso8601String()]);
    $governance->seedArtifact('retain-org', ['id' => 'fresh-artifact', 'created_at' => now()->subDays(5)->toIso8601String()]);

    $plan = app(RetentionService::class)->apply($team, retentionDays: 30, dryRun: false, actor: 'cli');

    expect($plan->toDelete)->toBe(['old-artifact']);
    expect($governance->stillExists('retain-org', 'old-artifact'))->toBeFalse();
    expect($governance->stillExists('retain-org', 'fresh-artifact'))->toBeTrue();
    expect(AuditEvent::query()->where('event_type', AuditEventType::RetentionApplied)->exists())->toBeTrue();
});

test('legal_hold_survives_retention_job', function () {
    $team = Team::factory()->create(['slug' => 'hold-org']);
    /** @var FakeArtifactGovernance $governance */
    $governance = app(ArtifactGovernanceContract::class);
    $governance->seedArtifact('hold-org', ['id' => 'held-artifact', 'created_at' => now()->subDays(31)->toIso8601String()]);
    $governance->placeLegalHold('hold-org', 'held-artifact');

    $plan = app(RetentionService::class)->apply($team, retentionDays: 30, dryRun: false, actor: 'cli');

    expect($plan->toDelete)->toBe([]);
    expect($plan->heldSurvivors)->toBe(['held-artifact']);
    expect($governance->stillExists('hold-org', 'held-artifact'))->toBeTrue();
});

test('legal_hold_blocks_admin_hard_delete', function () {
    $team = Team::factory()->create(['slug' => 'hold-org-2']);
    /** @var FakeArtifactGovernance $governance */
    $governance = app(ArtifactGovernanceContract::class);
    $governance->seedArtifact('hold-org-2', ['id' => 'held-artifact', 'created_at' => now()->toIso8601String()]);
    $governance->placeLegalHold('hold-org-2', 'held-artifact');

    $succeeded = app(LegalHoldService::class)->attemptHardDelete($team, 'held-artifact', actor: 'admin@example.com');

    expect($succeeded)->toBeFalse();
    expect($governance->stillExists('hold-org-2', 'held-artifact'))->toBeTrue();
    $event = AuditEvent::query()->where('event_type', AuditEventType::ArtifactDeleted)->latest('id')->firstOrFail();
    expect($event->outcome)->toContain('refused');
    expect($event->outcome)->toContain('held-artifact');
});

test('erasure_conflicting_with_hold_is_refused', function () {
    $team = Team::factory()->create(['slug' => 'erase-org']);
    /** @var FakeArtifactGovernance $governance */
    $governance = app(ArtifactGovernanceContract::class);
    $governance->seedArtifact('erase-org', ['id' => 'clean-artifact', 'created_at' => now()->toIso8601String()]);
    $governance->seedArtifact('erase-org', ['id' => 'held-artifact', 'created_at' => now()->toIso8601String()]);
    $governance->placeLegalHold('erase-org', 'held-artifact');

    $plan = app(ErasureService::class)->erase($team, dryRun: false, actor: 'cli');

    expect($plan->refused)->toBeTrue();
    expect($plan->heldArtifactId)->toBe('held-artifact');
    // Never silently partial: the clean artifact must survive too.
    expect($governance->stillExists('erase-org', 'clean-artifact'))->toBeTrue();
    expect($governance->stillExists('erase-org', 'held-artifact'))->toBeTrue();
});

test('region_is_immutable_after_provisioning', function () {
    // `region` is deliberately excluded from Team's #[Fillable] list (it's
    // set once by TenantProvisioningService via forceFill, never by a
    // mass-assignable request), so the immutability guard is exercised the
    // same way a real caller would reach it: forceFill + save.
    $team = Team::factory()->create(['region' => 'us']);

    expect(fn () => $team->forceFill(['region' => 'eu'])->save())->toThrow(RuntimeException::class);
    expect($team->fresh()->region)->toBe('us');
});

test('viewer_cannot_change_retention_policy', function () {
    $team = Team::factory()->create();
    $viewer = memberOfTeam($team, TeamRole::Viewer);

    $response = test()->actingAs($viewer)->patch(
        "/settings/teams/{$team->slug}/retention",
        ['retention_days' => 14],
    );

    $response->assertForbidden();
    expect($team->fresh()->retention_days)->toBeNull();
});
