<?php

namespace App\Http\Controllers\Teams;

use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Team;
use App\Models\User;
use App\Services\Billing\PlanGate;
use App\Services\Billing\PlanGateException;
use App\Services\Governance\SiemExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Read-only view of a team's audit trail, newest first, for governance
 * admins, plus the plan-gated JSON Lines export. Rows are append-only;
 * nothing here writes except the export's own audit entry.
 */
class AuditLogController extends Controller
{
    public function index(Request $request, Team $team): Response
    {
        $this->authorizeAdmin($request, $team);

        $filters = $request->validate([
            'type' => ['nullable', 'string'],
            'actor' => ['nullable', 'string', 'max:255'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $type = AuditEventType::tryFrom((string) ($filters['type'] ?? ''));

        $paginator = AuditEvent::query()
            ->where('team_id', $team->id)
            ->when($type, fn ($query) => $query->where('event_type', $type))
            ->when($filters['actor'] ?? null, fn ($query, $actor) => $query->where('actor', $actor))
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->where('created_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->where('created_at', '<=', $to.' 23:59:59'))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        $names = $this->actorNames($paginator->getCollection()->pluck('actor')->unique());

        $events = $paginator->through(fn (AuditEvent $event): array => [
            'id' => $event->id,
            'type' => $event->event_type->value,
            'actor' => $event->actor,
            'actorName' => $names[$event->actor] ?? $event->actor,
            'target' => $event->target,
            'outcome' => $event->outcome,
            'ip' => $event->ip,
            'at' => $event->created_at->toIso8601String(),
        ]);

        return Inertia::render('teams/audit', [
            'team' => ['slug' => $team->slug, 'name' => $team->name],
            'events' => $events,
            'types' => array_map(fn (AuditEventType $case): string => $case->value, AuditEventType::cases()),
            'filters' => [
                'type' => $type?->value,
                'actor' => $filters['actor'] ?? null,
                'from' => $filters['from'] ?? null,
                'to' => $filters['to'] ?? null,
            ],
            'canExport' => PlanGate::isEnterprise($team),
        ]);
    }

    public function export(Request $request, Team $team, SiemExportService $export): StreamedResponse
    {
        $this->authorizeAdmin($request, $team);

        try {
            $jsonl = $export->export($team, (string) $request->user()->id, (string) $request->ip(), (string) $request->userAgent());
        } catch (PlanGateException $exception) {
            abort(403, $exception->getMessage());
        }

        return response()->streamDownload(
            fn () => print ($jsonl),
            "{$team->slug}-audit.jsonl",
            ['Content-Type' => 'application/x-ndjson'],
        );
    }

    private function authorizeAdmin(Request $request, Team $team): void
    {
        abort_unless($request->user()->belongsToTeam($team), 404);
        Gate::authorize('manageGovernance', $team);
    }

    /**
     * Actor strings are user ids for people; anything else (`cli`,
     * `slack:unfurl`) is shown as is, and ids of deleted users read
     * "deleted user".
     *
     * @param  Collection<int, string>  $actors
     * @return array<string, string>
     */
    private function actorNames($actors): array
    {
        $ids = $actors->filter(fn (string $actor): bool => ctype_digit($actor))->values();
        $users = User::query()->whereIn('id', $ids)->pluck('name', 'id');

        return $ids->mapWithKeys(fn (string $id): array => [
            $id => (string) ($users[(int) $id] ?? __('deleted user')),
        ])->all();
    }
}
