<?php

namespace App\Services\Polis;

use App\Services\AuthKit\AuthKitProfile;
use RuntimeException;

/**
 * Real Ory Polis client. Not exercised by any automated test — Polis is a
 * self-hosted Docker service (`boxyhq/jackson`) not deployed in this
 * environment. Fails closed: throws unless the Polis base URL and API
 * key are configured, then throws "not implemented" regardless — the
 * real OAuth code-exchange call was never written, since there is no
 * live Polis instance to test it against.
 */
final class RealPolisClient implements PolisClientContract
{
    public function authenticateWithCode(string $code, string $tenant, string $product): AuthKitProfile
    {
        $baseUrl = config('services.polis.base_url');
        $apiKey = config('services.polis.api_key');

        if (! $baseUrl || ! $apiKey) {
            throw new RuntimeException('services.polis.base_url and api_key must be configured to authenticate via Polis.');
        }

        throw new RuntimeException('RealPolisClient::authenticateWithCode is not implemented — no live Ory Polis deployment in this environment.');
    }
}
