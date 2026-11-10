<?php

namespace App\Services\Analytics;

use App\Contracts\AnalyticsContract;
use RuntimeException;

/**
 * In-memory analytics sink for tests. Never talks to PostHog.
 * Set {@see $throwOnCapture} to exercise the call-site safety net.
 */
final class FakeAnalytics implements AnalyticsContract
{
    /**
     * @var list<array{
     *     type: 'capture'|'identify'|'group_identify',
     *     distinct_id?: string,
     *     event?: string,
     *     properties: array<string, mixed>,
     *     groups?: array<string, mixed>,
     *     group_type?: string,
     *     group_key?: string,
     *     set_once?: array<string, mixed>
     * }>
     */
    public array $records = [];

    public bool $throwOnCapture = false;

    public int $flushCount = 0;

    public function capture(string $distinctId, string $event, array $properties = [], array $groups = []): void
    {
        if ($this->throwOnCapture) {
            // Rethrow so ProductAnalytics::safe() (or call-site catch) is exercised.
            throw new RuntimeException('Fake PostHog capture failure.');
        }

        $this->records[] = [
            'type' => 'capture',
            'distinct_id' => $distinctId,
            'event' => $event,
            'properties' => $properties,
            'groups' => $groups,
        ];
    }

    public function identify(string $distinctId, array $properties = [], array $setOnce = []): void
    {
        if ($this->throwOnCapture) {
            throw new RuntimeException('Fake PostHog identify failure.');
        }

        $this->records[] = [
            'type' => 'identify',
            'distinct_id' => $distinctId,
            'properties' => $properties,
            'set_once' => $setOnce,
        ];
    }

    public function groupIdentify(string $groupType, string $groupKey, array $properties = []): void
    {
        if ($this->throwOnCapture) {
            throw new RuntimeException('Fake PostHog groupIdentify failure.');
        }

        $this->records[] = [
            'type' => 'group_identify',
            'group_type' => $groupType,
            'group_key' => $groupKey,
            'properties' => $properties,
        ];
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
     * @return list<array{
     *     type: 'capture'|'identify'|'group_identify',
     *     distinct_id?: string,
     *     event?: string,
     *     properties: array<string, mixed>,
     *     groups?: array<string, mixed>,
     *     group_type?: string,
     *     group_key?: string,
     *     set_once?: array<string, mixed>
     * }>
     */
    public function eventsNamed(string $event): array
    {
        return array_values(array_filter(
            $this->records,
            fn (array $record): bool => $record['type'] === 'capture' && ($record['event'] ?? null) === $event,
        ));
    }

    public function reset(): void
    {
        $this->records = [];
        $this->throwOnCapture = false;
        $this->flushCount = 0;
    }
}
