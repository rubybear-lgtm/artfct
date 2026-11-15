<?php

namespace App\Services\Templates;

/**
 * The pull failed. Carries the machine-readable error the caller renders in
 * its own shape, plus the pending artifact id when the Worker accepted the
 * create before a later step failed (so the puller can clean it up).
 */
final class PullFailedException extends \RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly bool $retryable = false,
        public readonly ?string $nextAction = null,
        public readonly array $details = [],
        public readonly ?string $artifactId = null,
        public readonly bool $telemetryRecorded = false,
    ) {
        parent::__construct($message);
    }
}
