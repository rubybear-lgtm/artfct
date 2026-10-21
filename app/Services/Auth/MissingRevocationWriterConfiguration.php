<?php

namespace App\Services\Auth;

use RuntimeException;

/**
 * Thrown when pending org-token revocations exist but the Worker denylist
 * writer is not configured, so no retry can ever succeed.
 */
final class MissingRevocationWriterConfiguration extends RuntimeException {}
