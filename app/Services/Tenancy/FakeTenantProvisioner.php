<?php

namespace App\Services\Tenancy;

use RuntimeException;

/**
 * In-memory double for tests. Records every call so a test can assert an
 * orchestration sequence happened (or was skipped, for idempotency), and
 * supports injecting a fault for one named org/step so partial-failure
 * runs (spec 09's explicit requirement — "executing a partial-failure
 * run, not describing one") are genuinely exercised rather than merely
 * asserted about.
 */
final class FakeTenantProvisioner implements TenantProvisionerContract
{
    /** @var list<array{method: string, org: string}> */
    public array $calls = [];

    /** @var array<string, string> org slug -> schema version's next value */
    private array $schemaVersions = [];

    /** @var array<string, true> "{org}:{method}" pairs that should throw */
    private array $faults = [];

    public function failNextCallFor(string $orgSlug, string $method): void
    {
        $this->faults["{$orgSlug}:{$method}"] = true;
    }

    public function clearFaultFor(string $orgSlug, string $method): void
    {
        unset($this->faults["{$orgSlug}:{$method}"]);
    }

    public function createDatabase(string $orgSlug): void
    {
        $this->record('createDatabase', $orgSlug);
    }

    public function createStoragePrefix(string $orgSlug): void
    {
        $this->record('createStoragePrefix', $orgSlug);
    }

    public function uploadScript(string $orgSlug, string $releaseVersion): void
    {
        $this->record('uploadScript', $orgSlug);
    }

    public function registerHostname(string $orgSlug): void
    {
        $this->record('registerHostname', $orgSlug);
    }

    public function removeScript(string $orgSlug): void
    {
        $this->record('removeScript', $orgSlug);
    }

    public function migrateTenantDatabase(string $orgSlug): int
    {
        $this->record('migrateTenantDatabase', $orgSlug);
        $next = ($this->schemaVersions[$orgSlug] ?? 0) + 1;
        $this->schemaVersions[$orgSlug] = $next;

        return $next;
    }

    /**
     * Every call recorded for one org, in order, method names only.
     *
     * @return list<string>
     */
    public function methodsCalledFor(string $orgSlug): array
    {
        return array_values(array_map(
            fn (array $call) => $call['method'],
            array_filter($this->calls, fn (array $call) => $call['org'] === $orgSlug),
        ));
    }

    private function record(string $method, string $orgSlug): void
    {
        $this->calls[] = ['method' => $method, 'org' => $orgSlug];

        $key = "{$orgSlug}:{$method}";
        if (isset($this->faults[$key])) {
            unset($this->faults[$key]);
            throw new RuntimeException("simulated failure: {$method} for {$orgSlug}");
        }
    }
}
