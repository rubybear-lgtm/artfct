<?php

namespace App\Services\Tenancy;

/**
 * Injectable seam over the Cloudflare-side provisioning primitives (spec
 * 09): one dispatch-namespace script, one D1 database, one R2 prefix, and
 * a hostname registration per tenant. {@see RealTenantProvisioner} calls
 * the actual Cloudflare API; {@see FakeTenantProvisioner} is bound in
 * tests and supports injecting a fault for one named tenant.
 *
 * Each method is one provisioning step, called individually by
 * {@see TenantProvisioningService} so a failure partway through a
 * provisioning run can be attributed to the exact step that failed — spec
 * 09's "Failure at any step leaves the org provisioning_failed with the
 * failed step recorded" depends on that granularity.
 *
 * NOTE: only the fake provisioner is exercised by this spec's test
 * suite. A real Workers for Platforms dispatch namespace is a paid
 * Cloudflare tier not enabled in this environment — see
 * backend/tests/dispatch_integration.rs for what real coverage would
 * require.
 */
interface TenantProvisionerContract
{
    public function createDatabase(string $orgSlug): void;

    public function createStoragePrefix(string $orgSlug): void;

    public function uploadScript(string $orgSlug, string $releaseVersion): void;

    public function registerHostname(string $orgSlug): void;

    /**
     * Removes the dispatch-namespace script only. D1 and R2 are retained
     * for the retention window (spec 11 defines its length) — this method
     * does not touch them.
     */
    public function removeScript(string $orgSlug): void;

    /**
     * Runs pending migrations against the tenant's D1 to head and returns
     * the resulting schema version. Throws on failure — callers must not
     * treat a thrown exception as fatal to a fleet run; see
     * {@see TenantFleetMigrator}.
     */
    public function migrateTenantDatabase(string $orgSlug): int;
}
