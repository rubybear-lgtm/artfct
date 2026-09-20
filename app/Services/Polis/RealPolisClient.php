<?php

namespace App\Services\Polis;

use App\Services\AuthKit\AuthKitProfile;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Real Ory Polis client over its OAuth 2.0 endpoints. A tenant (the org
 * slug) and product ride in the `client_id` as Polis expects
 * (`tenant=..&product=..`); the client secret is the shared verifier
 * configured on Polis as `CLIENT_SECRET_VERIFIER`. Fails closed: anything
 * missing or rejected throws, and the callback turns that into a 403.
 */
final class RealPolisClient implements PolisClientContract
{
    public function authorizationUrl(string $tenant, string $product, string $redirectUri, string $state): string
    {
        return $this->baseUrl().'/api/oauth/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $this->clientId($tenant, $product),
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ]);
    }

    public function authenticateWithCode(string $code, string $tenant, string $product): AuthKitProfile
    {
        $baseUrl = $this->baseUrl();

        $token = Http::asForm()->acceptJson()->timeout(20)->post($baseUrl.'/api/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $this->clientId($tenant, $product),
            'client_secret' => $this->clientSecret(),
            'redirect_uri' => route('sso.authenticate', ['team' => $tenant]),
            'code' => $code,
        ]);

        $accessToken = $token->json('access_token');

        if (! $token->successful() || ! is_string($accessToken) || $accessToken === '') {
            throw new RuntimeException('Polis rejected the authorization code.');
        }

        $user = Http::withToken($accessToken)->acceptJson()->timeout(20)->get($baseUrl.'/api/oauth/userinfo');

        if (! $user->successful() || ! is_string($user->json('id')) || ! is_string($user->json('email'))) {
            throw new RuntimeException('Polis returned no usable profile.');
        }

        // Polis echoes the tenant the login was issued for; refuse a code
        // minted for another org even if it is otherwise valid.
        $issuedFor = $user->json('requested.tenant');
        if (is_string($issuedFor) && $issuedFor !== $tenant) {
            throw new RuntimeException('Polis login was issued for a different tenant.');
        }

        return new AuthKitProfile(
            externalId: $user->json('id'),
            provider: 'polis',
            email: strtolower($user->json('email')),
            emailVerified: true,
            firstName: $user->json('firstName'),
            lastName: $user->json('lastName'),
            avatar: null,
        );
    }

    private function baseUrl(): string
    {
        $baseUrl = config('services.polis.base_url');

        if (! $baseUrl || ! config('services.polis.client_secret_verifier')) {
            throw new RuntimeException('services.polis.base_url and client_secret_verifier must be configured to sign in through Polis.');
        }

        return rtrim((string) $baseUrl, '/');
    }

    private function clientId(string $tenant, string $product): string
    {
        return 'tenant='.$tenant.'&product='.$product;
    }

    private function clientSecret(): string
    {
        return (string) config('services.polis.client_secret_verifier');
    }
}
