<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * An operator probe: dispatch it to confirm the queue worker service (not the
 * web process) picks jobs up, and that the worker resolves the same public
 * origin the web service serves. The queue service keeps its own copy of
 * APP_URL, so a drifted copy silently puts the wrong host in every link a
 * queued job mints -- invitations among them -- while the web service itself
 * looks perfectly healthy, which is exactly how staging served invitation
 * links pointing at a Railway service domain.
 */
class QueueProbeJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $marker) {}

    public function handle(): void
    {
        Log::info("QUEUE_PROBE {$this->marker} processed in pid ".getmypid().' app_url '.route('home'));
    }
}
