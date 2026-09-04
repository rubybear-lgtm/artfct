<?php

namespace App\Services\AuthKit;

use RuntimeException;

/**
 * Injectable stand-in for WorkOS AuthKit, bound outside production so the
 * full register -> callback -> session flow is exercisable without a live
 * WorkOS account (same "mock external services" pattern as ARTFCT_ORG_TOKEN
 * and the DNS resolver).
 *
 * Codes are self-describing (base64 JSON of the profile) rather than held
 * in server-side state, so they survive across the separate HTTP process a
 * Pest browser test drives — a shared in-memory queue would not.
 */
final class FakeAuthKitClient implements AuthKitClientContract
{
    /**
     * Encode a profile into an opaque "authorization code" that
     * {@see authenticateWithCode()} can turn back into the same profile.
     */
    public static function codeFor(AuthKitProfile $profile): string
    {
        return base64_encode(json_encode([
            'external_id' => $profile->externalId,
            'provider' => $profile->provider,
            'email' => $profile->email,
            'email_verified' => $profile->emailVerified,
            'first_name' => $profile->firstName,
            'last_name' => $profile->lastName,
            'avatar' => $profile->avatar,
        ], JSON_THROW_ON_ERROR));
    }

    public function authenticateWithCode(string $code): AuthKitProfile
    {
        $decoded = json_decode((string) base64_decode($code, true), true);

        if (! is_array($decoded) || ! isset($decoded['external_id'], $decoded['provider'], $decoded['email'])) {
            throw new RuntimeException('Invalid fake AuthKit authorization code.');
        }

        return new AuthKitProfile(
            externalId: $decoded['external_id'],
            provider: $decoded['provider'],
            email: $decoded['email'],
            emailVerified: (bool) ($decoded['email_verified'] ?? false),
            firstName: $decoded['first_name'] ?? null,
            lastName: $decoded['last_name'] ?? null,
            avatar: $decoded['avatar'] ?? null,
        );
    }
}
