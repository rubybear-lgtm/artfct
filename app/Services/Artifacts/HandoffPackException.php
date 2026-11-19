<?php

namespace App\Services\Artifacts;

use RuntimeException;

/**
 * A handoff pack could not be built (RUB-564, spec 41). `code` is the stable
 * machine-readable reason (`handoff_not_mockup`, `handoff_missing`,
 * `handoff_readme_conflict`, `handoff_unavailable`, `handoff_unreadable`), and
 * `status` is the HTTP status to answer with. Nothing was written to a zip.
 */
final class HandoffPackException extends RuntimeException
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        public readonly array $errors,
    ) {
        parent::__construct($errors[0] ?? 'The handoff pack cannot be built.');
    }
}
