<?php

namespace App\Services\AuthKit;

use RuntimeException;
use WorkOS\UserManagement;
use WorkOS\WorkOS as WorkOSSdk;

/**
 * Talks to the real WorkOS AuthKit API. Fails closed: without
 * WORKOS_CLIENT_ID/WORKOS_API_KEY/WORKOS_REDIRECT_URL configured, no code
 * exchange is attempted — the same fail-closed pattern documented for
 * ARTFCT_ORG_TOKEN in DOCUMENTATION.md.
 */
final class RealAuthKitClient implements AuthKitClientContract
{
    public function authenticateWithCode(string $code): AuthKitProfile
    {
        $clientId = config('services.workos.client_id');

        if (! $clientId || ! config('services.workos.secret') || ! config('services.workos.redirect_url')) {
            throw new RuntimeException('WorkOS AuthKit is not configured (WORKOS_CLIENT_ID / WORKOS_API_KEY / WORKOS_REDIRECT_URL).');
        }

        WorkOSSdk::setClientId($clientId);
        WorkOSSdk::setApiKey(config('services.workos.secret'));

        $result = (new UserManagement)->authenticateWithCode($clientId, $code);

        return new AuthKitProfile(
            externalId: $result->user->id,
            provider: $result->authenticationMethod ?? 'unknown',
            email: $result->user->email,
            emailVerified: (bool) $result->user->emailVerified,
            firstName: $result->user->firstName,
            lastName: $result->user->lastName,
            avatar: $result->user->profilePictureUrl,
            sessionId: self::sessionIdFrom($result->accessToken),
        );
    }

    /**
     * The WorkOS session id is the access token's `sid` claim. The token came
     * straight from WorkOS over TLS in this exchange, so it is read, not verified.
     */
    private static function sessionIdFrom(?string $accessToken): ?string
    {
        $payload = explode('.', (string) $accessToken)[1] ?? null;
        $claims = $payload === null ? null : json_decode((string) base64_decode(strtr($payload, '-_', '+/')), true);

        return is_array($claims) && is_string($claims['sid'] ?? null) ? $claims['sid'] : null;
    }
}
