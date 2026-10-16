<?php

namespace App\Services\Operator;

use App\Services\Readiness\IntegrationReadiness;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Platform-wide counters for the operator console's health page (spec 17).
 * Counts and timestamps only: no tenant, artifact or content is named.
 */
final class PlatformHealth
{
    public function __construct(private readonly IntegrationReadiness $readiness) {}

    /**
     * @return array{
     *     queue: array{failed_jobs: int, pending_jobs: int},
     *     indexing: array{failures: int},
     *     org_token_revocations: array{pending: int},
     *     stripe: array{last_received_at: string|null},
     *     worker_events: array{last_received_at: string|null},
     *     readiness: list<array{name: string, state: string, missing: list<string>}>,
     *     staff_organization: array{configured: bool, id: string|null},
     * }
     */
    public function snapshot(): array
    {
        $staffOrganizationId = config('services.workos.staff_organization_id');
        $staffOrganizationId = is_string($staffOrganizationId) && $staffOrganizationId !== '' ? $staffOrganizationId : null;

        return [
            'queue' => [
                'failed_jobs' => DB::table('failed_jobs')->count(),
                'pending_jobs' => DB::table('jobs')->count(),
            ],
            'indexing' => ['failures' => DB::table('artifact_indexing_failures')->count()],
            'org_token_revocations' => [
                'pending' => DB::table('org_token_revocation_retries')->whereNull('completed_at')->count(),
            ],
            'stripe' => ['last_received_at' => $this->newest('stripe_events_received')],
            'worker_events' => ['last_received_at' => $this->newest('worker_events_received')],
            'readiness' => $this->readiness->check(),
            'staff_organization' => [
                'configured' => $staffOrganizationId !== null,
                'id' => $staffOrganizationId,
            ],
        ];
    }

    private function newest(string $table): ?string
    {
        $receivedAt = DB::table($table)->max('received_at');

        return $receivedAt === null ? null : Carbon::parse($receivedAt)->toIso8601String();
    }
}
