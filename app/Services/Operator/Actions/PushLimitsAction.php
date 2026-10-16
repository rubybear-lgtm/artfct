<?php

namespace App\Services\Operator\Actions;

use App\Enums\AuditEventType;
use App\Models\Team;
use App\Services\Billing\OrgLimitsWriter;
use Illuminate\Http\Request;

/**
 * Re-pushes a tenant's plan limits and payment state to the Worker (spec 17).
 * The same `OrgLimitsWriter::push()` call `billing:sync-limits` makes, so
 * both write identical `org_limits` rows. Per-tenant custom numbers are not
 * supported: the next sync would overwrite them.
 */
final class PushLimitsAction
{
    public function __construct(
        private readonly OperatorActionRecorder $recorder,
        private readonly OrgLimitsWriter $writer,
    ) {}

    public function run(Request $request, Team $team, string $reason): string
    {
        $type = AuditEventType::OperatorLimitsChanged;

        $this->recorder->refuseIfDeleted($request, $type, $team, $team->slug, $reason);

        if (! $this->writer->push($team)) {
            $this->recorder->refuse($request, $type, $team, $team->slug, $reason, 'action', 'The Worker did not accept the limits push.');
        }

        $this->recorder->succeed($request, $type, $team, $team->slug, $reason);

        return 'Limits pushed to the Worker.';
    }
}
