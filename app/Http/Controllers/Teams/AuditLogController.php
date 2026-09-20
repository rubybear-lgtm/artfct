<?php

namespace App\Http\Controllers\Teams;

use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Read-only view of a team's audit trail, newest first, for governance
 * admins. Rows are append-only; nothing here writes.
 */
class AuditLogController extends Controller
{
    public function index(Request $request, Team $team): Response
    {
        abort_unless($request->user()->belongsToTeam($team), 404);
        Gate::authorize('manageGovernance', $team);

        $type = AuditEventType::tryFrom((string) $request->query('type'));

        $events = AuditEvent::query()
            ->where('team_id', $team->id)
            ->when($type, fn ($query) => $query->where('event_type', $type))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (AuditEvent $event): array => [
                'id' => $event->id,
                'type' => $event->event_type->value,
                'actor' => $event->actor,
                'target' => $event->target,
                'outcome' => $event->outcome,
                'ip' => $event->ip,
                'at' => $event->created_at->toIso8601String(),
            ]);

        return Inertia::render('teams/audit', [
            'team' => ['slug' => $team->slug, 'name' => $team->name],
            'events' => $events,
            'types' => array_map(fn (AuditEventType $case): string => $case->value, AuditEventType::cases()),
            'selectedType' => $type?->value,
        ]);
    }
}
