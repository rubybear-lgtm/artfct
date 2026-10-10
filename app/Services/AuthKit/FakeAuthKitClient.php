<?php

namespace App\Services\AuthKit;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

/**
 * Injectable stand-in for WorkOS AuthKit, bound outside production so the
 * full register -> callback -> session flow is exercisable without a live
 * WorkOS account (same "mock external services" pattern as ARTFCT_ORG_TOKEN
 * and the DNS resolver).
 *
 * Codes are self-describing (the profile, encrypted with the app key, with a
 * short expiry) rather than held in server-side state, so they survive
 * across the separate HTTP process a Pest browser test drives — a shared
 * in-memory queue would not. They are encrypted, not merely encoded: an
 * unsigned code would let anyone who can reach `/authenticate` forge a
 * profile (any email) and take over the matching account.
 */
final class FakeAuthKitClient implements AuthKitClientContract
{
    /**
     * Encode a profile into an opaque "authorization code" that
     * {@see authenticateWithCode()} can turn back into the same profile.
     */
    public static function codeFor(AuthKitProfile $profile): string
    {
        return Crypt::encryptString(json_encode([
            'external_id' => $profile->externalId,
            'provider' => $profile->provider,
            'email' => $profile->email,
            'email_verified' => $profile->emailVerified,
            'first_name' => $profile->firstName,
            'last_name' => $profile->lastName,
            'avatar' => $profile->avatar,
            'expires_at' => now()->addMinutes(5)->timestamp,
        ], JSON_THROW_ON_ERROR));
    }

    public function authenticateWithCode(string $code): AuthKitProfile
    {
        try {
            $decoded = json_decode(Crypt::decryptString($code), true);
        } catch (DecryptException) {
            throw new RuntimeException('Invalid fake AuthKit authorization code.');
        }

        if (! is_array($decoded) || ! isset($decoded['external_id'], $decoded['provider'], $decoded['email'])) {
            throw new RuntimeException('Invalid fake AuthKit authorization code.');
        }

        if (($decoded['expires_at'] ?? 0) < now()->timestamp) {
            throw new RuntimeException('Expired fake AuthKit authorization code.');
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
