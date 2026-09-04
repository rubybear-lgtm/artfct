<?php

namespace App\Services\Billing;

/**
 * A point-in-time read of an org's quota consumption. Pure data — the
 * console renders a warning banner from `storageWarning`/`artifactsWarning`
 * without touching `QuotaService` again.
 */
final readonly class QuotaStatus
{
    public function __construct(
        public float $storagePercent,
        public float $artifactsPercent,
        public bool $storageWarning,
        public bool $artifactsWarning,
        public bool $storageExceeded,
        public bool $artifactsExceeded,
    ) {}

    public function anyWarning(): bool
    {
        return $this->storageWarning || $this->artifactsWarning;
    }

    public function anyExceeded(): bool
    {
        return $this->storageExceeded || $this->artifactsExceeded;
    }
}
