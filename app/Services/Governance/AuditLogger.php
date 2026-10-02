<?php

namespace App\Services\Governance;

use App\Enums\AuditEventType;
use App\Models\AuditEvent;
use App\Models\Team;
use App\Support\ClientIp;
use Illuminate\Http\Request;

/**
 * Writes append-only audit rows (spec 11). Every write goes through
 * `AuditEvent::create()` — the model itself refuses any subsequent
 * update/delete, so this class has no corresponding "correct" or "remove"
 * method to define.
 */
final class AuditLogger
{
    public function record(
        AuditEventType $type,
        ?Team $team,
        string $actor,
        string $target,
        string $ip,
        string $userAgent,
        string $outcome = 'success',
    ): AuditEvent {
        return AuditEvent::create([
            'team_id' => $team?->id,
            'event_type' => $type,
            'actor' => $actor,
            'target' => $target,
            'ip' => $ip,
            'user_agent' => $userAgent,
            'outcome' => $outcome,
        ]);
    }

    /**
     * Convenience for controller call sites: pulls actor/IP/user-agent from
     * the current request rather than making every caller thread them
     * through by hand.
     */
    public function recordForRequest(
        Request $request,
        AuditEventType $type,
        ?Team $team,
        string $actor,
        string $target,
        string $outcome = 'success',
    ): AuditEvent {
        return $this->record(
            $type,
            $team,
            $actor,
            $target,
            ClientIp::for($request),
            $request->userAgent() ?? 'unknown',
            $outcome,
        );
    }
}
