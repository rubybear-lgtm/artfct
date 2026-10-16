<?php

namespace App\Console\Commands;

use App\Enums\AuditEventType;
use App\Models\OperatorSuppressionCase;
use App\Models\Team;
use App\Services\Operator\OperatorAuditLogger;
use App\Services\Operator\SuppressionCaseService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ops:release-suppressed')]
#[Description('Writes events withheld by expired suppression cases to the tenant audit log with their original timestamps')]
class OpsReleaseSuppressedCommand extends Command
{
    public function handle(SuppressionCaseService $cases, OperatorAuditLogger $logger): int
    {
        $released = 0;
        $written = 0;

        OperatorSuppressionCase::query()
            ->pendingRelease()
            ->orderBy('id')
            ->get()
            ->each(function (OperatorSuppressionCase $case) use ($cases, $logger, &$released, &$written): void {
                $count = $cases->publishWithheldEvents($case);

                if ($count === null) {
                    return;
                }

                $logger->record(
                    AuditEventType::OperatorCaseReleased,
                    null,
                    Team::query()->find($case->team_id),
                    "case:{$case->id}",
                    'internal',
                    'ops:release-suppressed',
                    "expired, {$count} withheld event(s) written, reference {$case->reference}",
                );

                $released++;
                $written += $count;
            });

        $this->components->info("Released {$released} case(s), wrote {$written} withheld event(s).");

        return self::SUCCESS;
    }
}
