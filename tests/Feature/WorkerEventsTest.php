<?php

use App\Enums\AuditEventType;
use App\Enums\TeamRole;
use App\Enums\UsageEventType;
use App\Jobs\ProcessWorkerEvent;
use App\Models\ArtifactUsageEvent;
use App\Models\AuditEvent;
use App\Models\McpActivity;
use App\Models\Team;
use App\Models\User;
use App\Services\WorkerEvents\ArtifactViewedHandler;
use App\Services\WorkerEvents\WorkerEventHandlers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['services.worker_events.secret' => 'test-secret']);
});

test('valid_signed_event_is_accepted_and_recorded', function () {
    $id = (string) Str::uuid();
    $handled = [];
    app(WorkerEventHandlers::class)->register('artifact.created', function (array $event) use (&$handled) {
        $handled[] = $event['id'];
    });

    postWorkerEvent(['id' => $id])->assertStatus(202);

    expect(DB::table('worker_events_received')->where('event_id', $id)->where('type', 'artifact.created')->where('org_id', 'acme')->count())->toBe(1)
        ->and($handled)->toBe([$id]);
});

test('bad_signature_rejected', function () {
    postWorkerEvent(secret: 'wrong-secret')->assertStatus(401);

    expect(DB::table('worker_events_received')->count())->toBe(0);
});

test('accepted_events_are_queued_without_running_handlers_inline', function () {
    Queue::fake();
    $handled = false;
    app(WorkerEventHandlers::class)->register('artifact.created', function () use (&$handled): void {
        $handled = true;
    });

    postWorkerEvent()->assertStatus(202);

    expect($handled)->toBeFalse();
    Queue::assertPushedOn('events', ProcessWorkerEvent::class, function (ProcessWorkerEvent $job): bool {
        return $job->event['type'] === 'artifact.created';
    });
});

test('stale_timestamp_rejected', function () {
    postWorkerEvent(timestamp: time() - 600)->assertStatus(401);

    expect(DB::table('worker_events_received')->count())->toBe(0);
});

test('replayed_event_id_is_noop', function () {
    $id = (string) Str::uuid();
    $calls = 0;
    app(WorkerEventHandlers::class)->register('artifact.created', function () use (&$calls) {
        $calls++;
    });

    postWorkerEvent(['id' => $id])->assertStatus(202);
    postWorkerEvent(['id' => $id])->assertOk();

    expect(DB::table('worker_events_received')->where('event_id', $id)->count())->toBe(1)
        ->and($calls)->toBe(1);
});

test('unknown_event_type_is_ignored_not_errored', function () {
    postWorkerEvent(['type' => 'artifact.teleported'])->assertStatus(202);

    expect(DB::table('worker_events_received')->count())->toBe(1);
});

test('artifact_viewed_event_records_audit_and_usage_without_raw_payloads', function () {
    Queue::fake();
    $team = Team::factory()->create(['slug' => 'acme']);
    $viewer = memberOfTeam($team, TeamRole::Member);
    $eventId = (string) Str::uuid();
    McpActivity::factory()->create([
        'team_id' => $team->id,
        'actor' => (string) $viewer->id,
        'tool' => 'get_artifact',
        'artifact_id' => 'artifact-123',
        'outcome' => 'success',
        'created_at' => now(),
    ]);

    postWorkerEvent([
        'id' => $eventId,
        'type' => 'artifact.viewed',
        'data' => [
            'artifact_id' => 'artifact-123',
            'viewer_user_id' => (string) $viewer->id,
            'html' => '<script>must never be persisted</script>',
        ],
    ])->assertAccepted();

    Queue::pushed(ProcessWorkerEvent::class)->first()->handle(app(WorkerEventHandlers::class));

    expect(AuditEvent::query()
        ->where('team_id', $team->id)
        ->where('event_type', AuditEventType::ArtifactViewed)
        ->where('target', 'artifact:artifact-123')
        ->where('actor', (string) $viewer->id)
        ->exists())->toBeTrue()
        ->and(ArtifactUsageEvent::query()
            ->where('team_id', $team->id)
            ->where('artifact_id', 'artifact-123')
            ->where('event_type', UsageEventType::RetrievedThenOpened)
            ->where('actor_user_id', $viewer->id)
            ->exists())->toBeTrue()
        ->and(AuditEvent::query()->where('team_id', $team->id)->get()->toJson())
        ->not->toContain('must never be persisted');
});

test('event_secret_unset_fails_closed', function () {
    config(['services.worker_events.secret' => null]);

    postWorkerEvent(secret: '')->assertStatus(401);

    expect(DB::table('worker_events_received')->count())->toBe(0);
});

// The MCP-side correlation: a `get_artifact` call is recorded as an
// `mcp_activities` row, and a later open by the same actor inside the window is
// recorded as `retrieved_then_opened` rather than a plain view. These drive the
// real handler and assert the real `artifact_usage_events` row, rather than
// hand-building the usage event and testing only its scoring.
function viewedEvent(Team $team, string $artifactId, int $viewerUserId, ?Carbon $occurredAt = null): array
{
    return [
        'org_id' => $team->slug,
        'occurred_at' => ($occurredAt ?? now())->toIso8601String(),
        'data' => ['artifact_id' => $artifactId, 'viewer_user_id' => $viewerUserId],
    ];
}

function usageEventTypes(Team $team, string $artifactId): array
{
    return ArtifactUsageEvent::query()
        ->where('team_id', $team->id)
        ->where('artifact_id', $artifactId)
        ->pluck('event_type')
        ->map(fn (UsageEventType $type): string => $type->value)
        ->all();
}

test('an mcp retrieval followed by an open is recorded as a correlated usage row', function () {
    $team = Team::factory()->create();
    $viewer = User::factory()->create();
    $artifactId = 'a'.str_repeat('b', 31);

    // The retrieval is a real mcp_activities row -- it is what the handler reads
    // to decide whether this open was preceded by an agent retrieval.
    McpActivity::factory()->create([
        'team_id' => $team->id,
        'tool' => 'get_artifact',
        'artifact_id' => $artifactId,
        'actor' => (string) $viewer->id,
        'outcome' => 'success',
        'created_at' => now()->subMinutes(5),
    ]);

    app(ArtifactViewedHandler::class)->handle(viewedEvent($team, $artifactId, $viewer->id));

    expect(usageEventTypes($team, $artifactId))->toBe(['retrieved_then_opened']);
});

test('an open with no preceding retrieval is recorded as a plain view', function () {
    $team = Team::factory()->create();
    $viewer = User::factory()->create();
    $artifactId = 'a'.str_repeat('c', 31);

    app(ArtifactViewedHandler::class)->handle(viewedEvent($team, $artifactId, $viewer->id));

    expect(usageEventTypes($team, $artifactId))->toBe(['viewed']);
});

test('a retrieval outside the correlation window does not correlate', function () {
    $team = Team::factory()->create();
    $viewer = User::factory()->create();
    $artifactId = 'a'.str_repeat('d', 31);

    // 31 minutes: past the handler's 30-minute window, so the open must not be
    // reported as agent-correlated. Without this the window would be untested.
    McpActivity::factory()->create([
        'team_id' => $team->id,
        'tool' => 'get_artifact',
        'artifact_id' => $artifactId,
        'actor' => (string) $viewer->id,
        'outcome' => 'success',
        'created_at' => now()->subMinutes(31),
    ]);

    app(ArtifactViewedHandler::class)->handle(viewedEvent($team, $artifactId, $viewer->id));

    expect(usageEventTypes($team, $artifactId))->toBe(['viewed']);
});

test('a retrieval by a different actor does not correlate', function () {
    $team = Team::factory()->create();
    $viewer = User::factory()->create();
    $other = User::factory()->create();
    $artifactId = 'a'.str_repeat('e', 31);

    McpActivity::factory()->create([
        'team_id' => $team->id,
        'tool' => 'get_artifact',
        'artifact_id' => $artifactId,
        'actor' => (string) $other->id,
        'outcome' => 'success',
        'created_at' => now()->subMinutes(5),
    ]);

    app(ArtifactViewedHandler::class)->handle(viewedEvent($team, $artifactId, $viewer->id));

    expect(usageEventTypes($team, $artifactId))->toBe(['viewed']);
});
