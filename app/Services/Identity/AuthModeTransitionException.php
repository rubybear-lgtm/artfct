<?php

namespace App\Services\Identity;

use RuntimeException;

/**
 * Thrown when an `auth_mode` transition is refused. The message names the
 * reason, per spec 06's DoD for the `polis` transition.
 */
class AuthModeTransitionException extends RuntimeException {}
