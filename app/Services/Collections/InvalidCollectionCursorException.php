<?php

namespace App\Services\Collections;

use InvalidArgumentException;

/**
 * Thrown when a list_collections / GET /api/collections cursor cannot be decoded.
 */
final class InvalidCollectionCursorException extends InvalidArgumentException
{
    public function __construct(string $message = 'The collection cursor is invalid.')
    {
        parent::__construct($message);
    }
}
