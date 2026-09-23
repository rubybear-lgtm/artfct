<?php

namespace App\Services\Governance;

use App\Enums\AuditEventType;
use App\Models\AuditEvent;
use App\Models\Team;
use App\Services\Billing\PlanGate;

/**
 * Scheduled and on-demand JSON Lines export of an org's audit log (spec
 * 11). Every export is itself audited (`export.performed`) — "who pulled
 * the audit log" is the first incident-response question, so the export
 * call records that fact before returning the export it produced,
 * guaranteeing the resulting export never claims to be complete without
 * mentioning itself. Enterprise-gated (spec 14): a `team`-plan org is
 * refused with a clear upgrade message before anything is queried or
 * audited.
 */
final class SiemExportService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function export(Team $team, string $actor, string $ip = 'internal', string $userAgent = 'governance:export'): string
    {
        PlanGate::requireEnterprise($team, 'SIEM export');

        $this->auditLogger->record(
            AuditEventType::ExportPerformed,
            $team,
            $actor,
            $team->slug,
            $ip,
            $userAgent,
        );

        return AuditEvent::query()
            ->where('team_id', $team->id)
            ->orderBy('created_at')
            ->get()
            ->map(fn (AuditEvent $event): string => json_encode([
                'event_type' => $event->event_type->value,
                'team' => $team->slug,
                'actor' => $event->actor,
                'target' => $event->target,
                'ip' => $event->ip,
                'user_agent' => $event->user_agent,
                'timestamp' => $event->created_at->toIso8601String(),
                'outcome' => $event->outcome,
            ], JSON_THROW_ON_ERROR)."\n")
            ->implode('');
    }
}
