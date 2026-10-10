<?php

namespace App\Services\Polis;

use App\Services\AuthKit\AuthKitProfile;
use App\Services\AuthKit\FakeAuthKitClient;
use RuntimeException;

/**
 * Injectable stand-in for Ory Polis, bound outside production. Mirrors
 * {@see FakeAuthKitClient}'s self-describing-code pattern exactly — codes
 * are base64 JSON of the profile plus tenant/product, not server-side
 * state, so they survive across a separate HTTP process the same way.
 */
final class FakePolisClient implements PolisClientContract
{
    public static function codeFor(AuthKitProfile $profile, string $tenant, string $product): string
    {
        return base64_encode(json_encode([
            'external_id' => $profile->externalId,
            'provider' => $profile->provider,
            'email' => $profile->email,
            'email_verified' => $profile->emailVerified,
            'first_name' => $profile->firstName,
            'last_name' => $profile->lastName,
            'avatar' => $profile->avatar,
            'tenant' => $tenant,
            'product' => $product,
        ], JSON_THROW_ON_ERROR));
    }

    public function authorizationUrl(string $tenant, string $product, string $redirectUri, string $state): string
    {
        return 'https://polis.fake/api/oauth/authorize?'.http_build_query([
            'tenant' => $tenant,
            'product' => $product,
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ]);
    }

    public function authenticateWithCode(string $code, string $tenant, string $product): AuthKitProfile
    {
        $decoded = json_decode((string) base64_decode($code, true), true);

        if (! is_array($decoded) || ! isset($decoded['external_id'], $decoded['provider'], $decoded['email'])) {
            throw new RuntimeException('Invalid fake Polis authorization code.');
        }

        if (($decoded['tenant'] ?? null) !== $tenant || ($decoded['product'] ?? null) !== $product) {
            throw new RuntimeException('Fake Polis authorization code was not issued for this tenant/product.');
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
