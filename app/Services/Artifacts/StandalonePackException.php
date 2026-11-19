<?php

namespace App\Services\Artifacts;

use RuntimeException;

/**
 * A bundle could not be packed into one standalone HTML file (RUB-563, spec 40).
 * `status` is the HTTP status the caller should answer with: 422 when the bundle
 * or one of its references is refused, 503 when the Worker did not list the
 * bundle's files. `errors` are the publish contract's strings, or the packer's
 * own refusals, and nothing was packed.
 */
final class StandalonePackException extends RuntimeException
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(
        public readonly int $status,
        public readonly array $errors,
    ) {
        parent::__construct($errors[0] ?? 'The artifact cannot be packed into one HTML file.');
    }
}
