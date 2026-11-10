<?php

namespace App\Contracts;

/**
 * Product analytics sink. Implementations must never throw to callers:
 * capture failures are swallowed and logged so requests keep succeeding.
 */
interface AnalyticsContract
{
    /**
     * @param  array<string, mixed>  $properties
     * @param  array<string, array<string, mixed>>  $groups  e.g. ['team' => ['id' => 'acme', ...]] unused on free plan but still sent
     */
    public function capture(string $distinctId, string $event, array $properties = [], array $groups = []): void;

    /**
     * @param  array<string, mixed>  $properties
     * @param  array<string, mixed>  $setOnce
     */
    public function identify(string $distinctId, array $properties = [], array $setOnce = []): void;

    /**
     * @param  array<string, mixed>  $properties
     */
    public function groupIdentify(string $groupType, string $groupKey, array $properties = []): void;

    public function flush(): void;

    public function enabled(): bool;
}
