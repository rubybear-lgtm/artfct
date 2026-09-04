<?php

namespace App\Services\Billing;

use RuntimeException;

/**
 * A bundle exceeding the tenant's per-artifact ceiling — distinct from
 * {@see QuotaExceededException} (which is about total usage, not one
 * artifact's size).
 */
final class BundleTooLargeException extends RuntimeException
{
    /** See {@see QuotaExceededException::$errorCode} for why this isn't named `$code`. */
    public string $errorCode = 'bundle_too_large';

    public function __construct(public readonly int $bundleSizeBytes, public readonly int $ceilingBytes)
    {
        parent::__construct("Bundle size {$bundleSizeBytes} bytes exceeds this tenant's {$ceilingBytes}-byte ceiling.");
    }
}
