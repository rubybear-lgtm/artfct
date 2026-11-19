<?php

namespace App\Services\Artifacts;

use RuntimeException;

/**
 * A PowerPoint export was refused (RUB-562, spec 46). `errorCode` is the stable
 * machine-readable reason, and `status` is the HTTP status to answer with.
 */
final class PptxExportException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
