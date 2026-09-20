<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * An operator probe: dispatch it to confirm the queue worker service (not the
 * web process) picks jobs up. It only writes a log line.
 */
class QueueProbeJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $marker) {}

    public function handle(): void
    {
        Log::info("QUEUE_PROBE {$this->marker} processed in pid ".getmypid());
    }
}
