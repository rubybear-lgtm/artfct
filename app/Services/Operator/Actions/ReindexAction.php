<?php

namespace App\Services\Operator\Actions;

use App\Enums\AuditEventType;
use App\Jobs\OperatorReindexJob;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Operator reindex of a tenant or one artifact (spec 17).
 *
 * Content-blind by construction: this only dispatches `OperatorReindexJob`
 * and returns a fixed sentence. The job runs `IndexBackfillService`, the one
 * implementation `indexing:backfill` also calls. No count, title or text
 * comes back to staff.
 */
final class ReindexAction
{
    public const STATUS = 'Reindex queued.';

    public function __construct(private readonly OperatorActionRecorder $recorder) {}

    public function run(Request $request, Team $team, ?string $artifactId, string $reason): string
    {
        $target = $artifactId === null ? $team->slug : "{$team->slug}:{$artifactId}";
        $type = AuditEventType::OperatorReindexRequested;

        $this->recorder->refuseIfDeleted($request, $type, $team, $target, $reason);

        if (! config('indexing.enabled')) {
            $this->recorder->refuse($request, $type, $team, $target, $reason, 'action', 'Indexing is turned off.');
        }

        // The job acts as the tenant's first admin, as `indexing:backfill`
        // does, so a tenant without one cannot be reindexed.
        if ($team->firstAdmin() === null) {
            $this->recorder->refuse($request, $type, $team, $target, $reason, 'action', 'This tenant has no admin to reindex as.');
        }

        // One whole-tenant reindex at a time. The job releases the key when
        // it finishes; it also lapses on its own.
        $queueKey = $artifactId === null ? OperatorReindexJob::queuedKey($team->id) : null;
        if ($queueKey !== null && ! Cache::add($queueKey, true, now()->addMinutes(10))) {
            $this->recorder->refuse($request, $type, $team, $target, $reason, 'action', 'A reindex for this tenant is already queued.');
        }

        try {
            OperatorReindexJob::dispatch($team->id, $artifactId)->onQueue('indexing');
        } catch (Throwable $exception) {
            report($exception);

            if ($queueKey !== null) {
                Cache::forget($queueKey);
            }

            $this->recorder->refuse($request, $type, $team, $target, $reason, 'action', 'The reindex could not be queued.');
        }

        $this->recorder->succeed($request, $type, $team, $target, $reason);

        return self::STATUS;
    }
}
