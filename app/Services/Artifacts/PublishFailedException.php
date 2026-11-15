<?php

namespace App\Services\Artifacts;

use RuntimeException;

/**
 * The publish core failed. Carries everything a caller needs to render its
 * own error shape (MCP error, pull refusal, HTTP redirect) without the
 * publisher knowing the surface.
 */
final class PublishFailedException extends RuntimeException
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
    ) {
        parent::__construct($message);
    }
}
