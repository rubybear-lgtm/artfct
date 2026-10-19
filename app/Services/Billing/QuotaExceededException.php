<?php

namespace App\Services\Billing;

use RuntimeException;

/**
 * Thrown when a new artifact would exceed the org's quota. DoD: "refused
 * new artifact creation with `code: quota_exceeded`" — `code` carries the
 * literal string a controller/console maps to that response shape.
 */
final class QuotaExceededException extends RuntimeException
{
    /**
     * The machine-readable error code (DoD: "refused new artifact
     * creation with `code: quota_exceeded`"). Named `$errorCode`, not
     * `$code` — `Exception::$code` is a built-in, untyped `int` property;
     * redeclaring it as `string` in a subclass is a PHP fatal error
     * ("Type of ...::$code must be omitted to match the parent
     * definition"), not merely a lint warning.
     */
    public string $errorCode = 'quota_exceeded';

    public function __construct(string $reason)
    {
        parent::__construct($reason);
    }
}
