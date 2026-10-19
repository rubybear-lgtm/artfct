<?php

namespace App\Services\Identity;

use App\Models\User;

/**
 * Result of {@see EnterpriseIdentityResolver::resolveOrgLogin()} — one of
 * spec 10's three named outcomes, never a silent user creation.
 */
final class EnterpriseLoginResult
{
    private function __construct(
        public readonly string $status,
        public readonly ?User $user,
    ) {}

    public static function matched(User $user): self
    {
        return new self('matched', $user);
    }

    /**
     * The incoming identity's email matched no existing member of this
     * org. Spec 10: "an unmatched SAML login never silently creates a
     * second user in an org that already has one for that person" — no
     * user is created; this must be surfaced for an admin to link
     * manually.
     */
    public static function unlinked(): self
    {
        return new self('unlinked', null);
    }
}
