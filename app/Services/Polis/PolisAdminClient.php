<?php

namespace App\Services\Polis;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Polis's admin API (`/api/v1/sso`), used to show and manage an org's SSO
 * connection from Laravel. The tenant is the org slug and the product is
 * always `artfct`. Authenticated with the Polis API key; fails closed when
 * Polis is not configured.
 */
class PolisAdminClient
{
    private const PRODUCT = 'artfct';

    /**
     * @return list<array{type: string, name: string}>
     */
    public function connections(string $tenant): array
    {
        $response = $this->http()->get($this->baseUrl().'/api/v1/sso', ['tenant' => $tenant, 'product' => self::PRODUCT])->throw();

        return collect($response->json() ?? [])
            ->filter(fn ($connection): bool => is_array($connection))
            ->map(fn (array $connection): array => [
                'type' => isset($connection['oidcProvider']) || isset($connection['oidcDiscoveryUrl']) ? 'OIDC' : 'SAML',
                'name' => (string) ($connection['name'] ?? $connection['idpMetadata']['provider'] ?? 'Identity provider'),
            ])->values()->all();
    }

    public function createSamlConnection(string $tenant, string $metadataUrl, string $redirectUrl): void
    {
        $this->http()->asForm()->post($this->baseUrl().'/api/v1/sso', [
            'tenant' => $tenant,
            'product' => self::PRODUCT,
            'defaultRedirectUrl' => $redirectUrl,
            'redirectUrl' => json_encode([$redirectUrl]),
            'metadataUrl' => $metadataUrl,
        ])->throw();
    }

    public function deleteConnections(string $tenant): void
    {
        $this->http()->delete($this->baseUrl().'/api/v1/sso', ['tenant' => $tenant, 'product' => self::PRODUCT])->throw();
    }

    private function baseUrl(): string
    {
        $baseUrl = config('services.polis.base_url');

        if (! $baseUrl || ! config('services.polis.api_key')) {
            throw new RuntimeException('services.polis.base_url and api_key must be configured to manage SSO connections.');
        }

        return rtrim((string) $baseUrl, '/');
    }

    private function http(): PendingRequest
    {
        return Http::withHeaders(['Authorization' => 'Api-Key '.config('services.polis.api_key')])->acceptJson()->timeout(20);
    }
}
