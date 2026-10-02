<?php

namespace App\Services\AuthKit;

/**
 * The seam between artfct's identity resolution and WorkOS AuthKit.
 *
 * Bound to {@see RealAuthKitClient} in production/local, and to
 * {@see FakeAuthKitClient} in tests — the same "mock external services"
 * pattern used for the DNS resolver and the Worker's ARTFCT_ORG_TOKEN.
 */
interface AuthKitClientContract
{
    /**
     * Exchange an AuthKit authorization code for a normalized profile.
     */
    public function authenticateWithCode(string $code): AuthKitProfile;
}
