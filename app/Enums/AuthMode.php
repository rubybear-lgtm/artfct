<?php

namespace App\Enums;

/**
 * An org's auth state machine (spec 06): `authkit` -> `dual` -> `polis`,
 * and back. Transition preconditions live in
 * App\Services\Identity\AuthModeTransitioner, not here — this enum is
 * just the set of valid states.
 */
enum AuthMode: string
{
    case AuthKit = 'authkit';
    case Dual = 'dual';
    case Polis = 'polis';
}
