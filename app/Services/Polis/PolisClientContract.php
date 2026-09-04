<?php

namespace App\Services\Polis;

use App\Services\AuthKit\AuthKitProfile;

/**
 * The seam between artfct's identity resolution and Ory Polis, spec 10's
 * self-hosted SAML/OIDC broker. Polis abstracts both protocols into a
 * standard OAuth 2.0 authorization code flow, so this returns the same
 * {@see AuthKitProfile} shape AuthKit does — `provider` is already a
 * plain string field, so a Polis login just sets it to `polis` (SAML) or
 * `polis-oidc` (OIDC) rather than needing a parallel profile type.
 *
 * Bound to {@see RealPolisClient} in production, and to
 * {@see FakePolisClient} in tests — the same "mock external services"
 * pattern used for WorkOS AuthKit, the DNS resolver, and the Worker's
 * ARTFCT_ORG_TOKEN.
 */
interface PolisClientContract
{
    /**
     * Exchange a Polis authorization code for a normalized profile, keyed
     * by tenant (the org id) and product (always "artfct" for this app).
     */
    public function authenticateWithCode(string $code, string $tenant, string $product): AuthKitProfile;
}
