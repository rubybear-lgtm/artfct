<?php

namespace App\Services\Billing;

final class FakeUsage implements UsageContract
{
    /** @var array<string, array{storage_bytes: int, artifacts_this_period: int, render_minutes_this_period: int}> */
    private array $usage = [];

    public function setUsage(string $orgSlug, int $storageBytes, int $artifactsThisPeriod, int $renderMinutesThisPeriod = 0): void
    {
        $this->usage[$orgSlug] = [
            'storage_bytes' => $storageBytes,
            'artifacts_this_period' => $artifactsThisPeriod,
            'render_minutes_this_period' => $renderMinutesThisPeriod,
        ];
    }

    public function currentUsage(string $orgSlug): array
    {
        return $this->usage[$orgSlug] ?? [
            'storage_bytes' => 0,
            'artifacts_this_period' => 0,
            'render_minutes_this_period' => 0,
        ];
    }
}
