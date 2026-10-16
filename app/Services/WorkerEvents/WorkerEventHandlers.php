<?php

namespace App\Services\WorkerEvents;

/**
 * Registry mapping an event `type` to its queued handler. Consuming issues
 * (`artifact.created`, `artifact.viewed`) register here; an unregistered
 * type is ignored so the Worker can ship new events first.
 */
final class WorkerEventHandlers
{
    /** @var array<string, callable(array<string, mixed>): void> */
    private array $handlers = [];

    /**
     * @param  callable(array<string, mixed>): void  $handler
     */
    public function register(string $type, callable $handler): void
    {
        $this->handlers[$type] = $handler;
    }

    /**
     * @param  array{type: string}&array<string, mixed>  $event
     * @return bool Whether a handler exists for the event's type.
     */
    public function dispatch(array $event): bool
    {
        $handler = $this->handlers[$event['type']] ?? null;

        if ($handler === null) {
            return false;
        }

        $handler($event);

        return true;
    }
}
