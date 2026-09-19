<?php

use App\Services\WorkerEvents\WorkerEventHandlers;
use Illuminate\Support\Facades\DB;
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

test('event_secret_unset_fails_closed', function () {
    config(['services.worker_events.secret' => null]);

    postWorkerEvent(secret: '')->assertStatus(401);

    expect(DB::table('worker_events_received')->count())->toBe(0);
});
