<?php

namespace App\Http\Controllers;

use App\Services\WorkerEvents\WorkerEventHandlers;
use App\Services\WorkerEvents\WorkerEventSignature;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * `POST /internal/worker-events` — the Worker's fire-and-forget event
 * channel (Worker -> Laravel), the reverse of `RevocationWriter`. Verifies
 * an HMAC over the raw body, records the event id for idempotency, then
 * queues the work; handlers never run in the request.
 */
class WorkerEventController extends Controller
{
    public function __invoke(Request $request, WorkerEventHandlers $handlers): JsonResponse
    {
        $secret = config('services.worker_events.secret');
        $signature = new WorkerEventSignature(is_string($secret) ? $secret : null);

        if (! $signature->verify(
            $request->header('X-Artfct-Timestamp'),
            $request->header('X-Artfct-Signature'),
            $request->getContent(),
            time(),
        )) {
            return response()->json(['error' => 'invalid_signature'], 401);
        }

        $validator = Validator::make($request->json()->all(), [
            'id' => ['required', 'uuid'],
            'type' => ['required', 'string'],
            'org_id' => ['required', 'string'],
            'occurred_at' => ['required', 'date'],
            'data' => ['present', 'array'],
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'validation_failed'], 422);
        }

        /** @var array{id: string, type: string, org_id: string, occurred_at: string, data: array<string, mixed>} $event */
        $event = $validator->validated();

        try {
            DB::table('worker_events_received')->insert([
                'event_id' => $event['id'],
                'type' => $event['type'],
                'org_id' => $event['org_id'],
                'received_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return response()->json(['status' => 'duplicate']);
        }

        if (! $handlers->dispatch($event)) {
            Log::info('Ignoring unknown worker event type.', ['type' => $event['type']]);
        }

        return response()->json(['status' => 'accepted'], 202);
    }
}
