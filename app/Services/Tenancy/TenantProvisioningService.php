<?php

namespace App\Services\Tenancy;

use App\Models\Team;
use Throwable;

/**
 * Orchestrates one tenant's provisioning/de-provisioning against a
 * {@see TenantProvisionerContract}, persisting progress on the {@see Team}
 * row between every step so a run killed midway leaves an accurate,
 * resumable record rather than silently half-provisioning a tenant (spec
 * 09: "Failure at any step leaves the org provisioning_failed with the
 * failed step recorded — never half-provisioned and silently broken").
 */
final class TenantProvisioningService
{
    /**
     * Ordered provisioning steps. Each entry is the step name (persisted
     * to `provisioning_failed_step` on failure) mapped to the provisioner
     * method that performs it.
     */
    private const array STEPS = [
        'create_database' => 'createDatabase',
        'create_storage_prefix' => 'createStoragePrefix',
        'upload_script' => 'uploadScript',
        'register_hostname' => 'registerHostname',
    ];

    public function __construct(
        private readonly TenantProvisionerContract $provisioner,
    ) {}

    /**
     * Provisions (or resumes provisioning) one tenant. Idempotent: an
     * already-provisioned org (non-null `provisioned_at`, no recorded
     * failed step) is a no-op. A previously failed run resumes from the
     * step that failed, not from the start, so already-completed steps
     * are never repeated against the provisioner.
     */
    public function provision(Team $team, string $releaseVersion): void
    {
        if ($team->provisioned_at !== null && $team->provisioning_failed_step === null) {
            return;
        }

        $resumeFrom = $team->provisioning_failed_step;
        $started = $resumeFrom === null;

        foreach (self::STEPS as $step => $method) {
            if (! $started) {
                if ($step === $resumeFrom) {
                    $started = true;
                } else {
                    continue;
                }
            }

            try {
                if ($method === 'uploadScript') {
                    $this->provisioner->uploadScript($team->slug, $releaseVersion);
                } else {
                    $this->provisioner->{$method}($team->slug);
                }
            } catch (Throwable $exception) {
                $team->provisioning_failed_step = $step;
                $team->save();

                throw $exception;
            }
        }

        $team->provisioned_at = now();
        $team->provisioning_failed_step = null;
        $team->release_version = $releaseVersion;
        $team->save();
    }

    /**
     * Removes the tenant's script only — D1 and R2 are retained for the
     * retention window (spec 11 defines its length).
     */
    public function deprovision(Team $team): void
    {
        $this->provisioner->removeScript($team->slug);
        $team->provisioned_at = null;
        $team->save();
    }
}
