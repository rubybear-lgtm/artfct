<?php

namespace App\Jobs;

use App\Services\WorkerEvents\WorkerEventHandlers;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessWorkerEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @param array<string, mixed> $event */
    public function __construct(public readonly array $event)
    {
        $this->onQueue('events');
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [5, 30, 120];
    }

    public function handle(WorkerEventHandlers $handlers): void
    {
        $handlers->dispatch($this->event);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Worker event processing failed.', [
            'event_id' => $this->event['id'] ?? null,
            'type' => $this->event['type'] ?? null,
            'error' => $exception->getMessage(),
        ]);
    }
}
