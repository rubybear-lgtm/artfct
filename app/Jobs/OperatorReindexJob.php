<?php

namespace App\Jobs;

use App\Models\Team;
use App\Services\Indexing\IndexBackfillService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * An operator console reindex (spec 17). The console only dispatches this: it
 * lives outside the `/ops` namespaces so that code never touches artifact
 * content, and it carries no content itself, only the tenant and, for a
 * single-artifact reindex, the artifact id.
 */
class OperatorReindexJob implements ShouldQueue
{
    use Queueable;

    /** @var int */
    public $tries = 1;

    /** @var int */
    public $timeout = 600;

    public function __construct(
        public readonly int $teamId,
        public readonly ?string $artifactId = null,
    ) {}

    public static function queuedKey(int $teamId): string
    {
        return "ops:reindex:{$teamId}";
    }

    public function handle(IndexBackfillService $service): void
    {
        // The backfill signs in the tenant's first admin on the global guard,
        // so the guard is put back whatever happens.
        $guard = auth()->guard();
        $previous = $guard->hasUser() ? $guard->user() : null;

        try {
            if (! config('indexing.enabled')) {
                return;
            }

            $team = Team::query()->findOrFail($this->teamId);

            if ($this->artifactId !== null) {
                $service->reindexArtifact($team, $this->artifactId);

                return;
            }

            $service->backfill($team);
        } finally {
            if ($previous !== null) {
                $guard->setUser($previous);
            } else {
                $guard->forgetUser();
            }

            if ($this->artifactId === null) {
                Cache::forget(self::queuedKey($this->teamId));
            }
        }
    }

    public function failed(Throwable $exception): void
    {
        if ($this->artifactId === null) {
            Cache::forget(self::queuedKey($this->teamId));
        }
    }
}
