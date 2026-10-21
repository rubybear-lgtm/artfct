<?php

namespace App\Services\Artifacts;

use RuntimeException;

/**
 * The Worker did not confirm an org-wide artifact operation (revoke, export).
 * Thrown for any answer other than a 2xx, and for a connection failure, so a
 * caller never treats an unconfirmed change as done.
 */
final class ArtifactServiceUnavailableException extends RuntimeException {}
