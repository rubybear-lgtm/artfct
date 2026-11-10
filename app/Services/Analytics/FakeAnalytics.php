<?php

namespace App\Services\Analytics;

use App\Contracts\AnalyticsContract;
use RuntimeException;
use Throwable;

/**
 * In-memory analytics sink for tests. Never talks to PostHog.
 * Set {@see $throwOnCapture} to exercise the call-site safety net.
 */
final class FakeAnalytics implements AnalyticsContract
{
    /** @var list<array{type: string, distinct_id?: string, event?: string, properties: array<string, mixed>, groups?: array<string, mixed>, group_type?: string, group_key?: string, set_once?: array<string, mixed>}> */
    public array $records = [];

    public bool $throwOnCapture = false;

    public int $flushCount = 0;

    public function capture(string $distinctId, string $event, array $properties = [], array $groups = []): void
    {
        try {
            if ($this->throwOnCapture) {
                throw new RuntimeException('Fake PostHog capture failure.');
            }

            $this->records[] = [
                'type' => 'capture',
                'distinct_id' => $distinctId,
                'event' => $event,
                'properties' => $properties,
                'groups' => $groups,
            ];
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    public function identify(string $distinctId, array $properties = [], array $setOnce = []): void
    {
        try {
            if ($this->throwOnCapture) {
                throw new RuntimeException('Fake PostHog identify failure.');
            }

            $this->records[] = [
                'type' => 'identify',
                'distinct_id' => $distinctId,
                'properties' => $properties,
                'set_once' => $setOnce,
            ];
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    public function groupIdentify(string $groupType, string $groupKey, array $properties = []): void
    {
        try {
            if ($this->throwOnCapture) {
                throw new RuntimeException('Fake PostHog groupIdentify failure.');
            }

            $this->records[] = [
                'type' => 'group_identify',
                'group_type' => $groupType,
                'group_key' => $groupKey,
                'properties' => $properties,
            ];
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    public function flush(): void
    {
        $this->flushCount++;
    }

    public function enabled(): bool
    {
        return true;
    }

    /**
     * @return list<array{type: string, distinct_id?: string, event?: string, properties: array<string, mixed>, groups?: array<string, mixed>}>
     */
    public function eventsNamed(string $event): array
    {
        return array_values(array_filter(
            $this->records,
            fn (array $record): bool => ($record['type'] ?? null) === 'capture' && ($record['event'] ?? null) === $event,
        ));
    }

    public function reset(): void
    {
        $this->records = [];
        $this->throwOnCapture = false;
        $this->flushCount = 0;
    }
}
