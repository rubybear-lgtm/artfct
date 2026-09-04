<?php

namespace App\Services\Tenancy;

use RuntimeException;

/**
 * Real Cloudflare-side provisioner. Not exercised by any automated test —
 * a real Workers for Platforms dispatch namespace is a paid Cloudflare
 * tier not enabled in this environment. Fails closed: every method throws
 * unless the required configuration is present, so an unconfigured
 * deployment can never silently no-op a provisioning step.
 */
final class RealTenantProvisioner implements TenantProvisionerContract
{
    public function createDatabase(string $orgSlug): void
    {
        $this->requireConfigured();
        throw new RuntimeException('RealTenantProvisioner::createDatabase is not implemented — no live Workers for Platforms account in this environment.');
    }

    public function createStoragePrefix(string $orgSlug): void
    {
        $this->requireConfigured();
        throw new RuntimeException('RealTenantProvisioner::createStoragePrefix is not implemented — no live Workers for Platforms account in this environment.');
    }

    public function uploadScript(string $orgSlug, string $releaseVersion): void
    {
        $this->requireConfigured();
        throw new RuntimeException('RealTenantProvisioner::uploadScript is not implemented — no live Workers for Platforms account in this environment.');
    }

    public function registerHostname(string $orgSlug): void
    {
        $this->requireConfigured();
        throw new RuntimeException('RealTenantProvisioner::registerHostname is not implemented — no live Workers for Platforms account in this environment.');
    }

    public function removeScript(string $orgSlug): void
    {
        $this->requireConfigured();
        throw new RuntimeException('RealTenantProvisioner::removeScript is not implemented — no live Workers for Platforms account in this environment.');
    }

    public function migrateTenantDatabase(string $orgSlug): int
    {
        $this->requireConfigured();
        throw new RuntimeException('RealTenantProvisioner::migrateTenantDatabase is not implemented — no live Workers for Platforms account in this environment.');
    }

    private function requireConfigured(): void
    {
        $apiToken = config('services.cloudflare.api_token');
        $accountId = config('services.cloudflare.account_id');
        $dispatchNamespace = config('services.cloudflare.dispatch_namespace');

        if (! $apiToken || ! $accountId || ! $dispatchNamespace) {
            throw new RuntimeException('services.cloudflare.api_token, account_id, and dispatch_namespace must be configured to provision tenants.');
        }
    }
}
