<?php

namespace App\Services\Operator;

use App\Enums\AuditEventType;
use App\Enums\PaymentStatus;
use App\Enums\Plan;
use App\Enums\TeamRole;
use App\Models\AuditEvent;
use App\Models\McpConnection;
use App\Models\OrgToken;
use App\Models\Team;
use App\Models\User;
use App\Services\Billing\UsageContract;
use App\Services\Operator\Actions\EraseTenantAction;
use App\Services\Polis\PolisAdminClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;
use UnexpectedValueException;

/**
 * Everything the operator console's tenant page shows (spec 17, phase 1).
 *
 * The content boundary lives here: this class reads identity, billing,
 * membership, connection and audit data, plus the Worker's usage figures. It
 * never reads an artifact, a title, indexed text or a comment, and it never
 * carries an artifact count of its own.
 */
final class TenantDetail
{
    /** The most recent audit events the page lists. */
    public const EVENT_LIMIT = 25;

    /** The most admins the members panel lists; `members.total` stays the real count. */
    public const ADMIN_LIMIT = 50;

    /**
     * Event types whose `target` is shown to staff, matched on the start of
     * the wire value. Everything else (artifact, share, search, legal hold
     * and any type not listed) gets `target: null`, because
     * `SearchService` stores the query text there and artifact targets name
     * content. Add a prefix here only for events whose target is an id, a
     * role, an email or a slug.
     *
     * @var list<string>
     */
    public const TARGET_ALLOWLIST = [
        'member.',
        'role.',
        'token.',
        'auth_mode.',
        'owner.',
        'billing.',
        'team.',
        'mcp.',
        'operator.',
        'staff.',
        'retention.',
        'export.',
        'public_sharing.',
        'invitation.',
        'account.',
    ];

    public function __construct(
        private readonly UsageContract $usage,
        private readonly PolisAdminClient $polis,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function tenant(Team $team): array
    {
        $customerId = $team->getAttribute('stripe_customer_id');
        $customerId = is_string($customerId) && $customerId !== '' ? $customerId : null;
        $subscriptionId = $team->stripe_subscription_id !== '' ? $team->stripe_subscription_id : null;
        $dashboard = $this->stripeDashboardBase();

        return [
            'slug' => $team->slug,
            'name' => $team->name,
            'plan' => ($team->plan ?? Plan::Free)->value,
            'payment_status' => ($team->payment_status ?? PaymentStatus::Active)->value,
            'owner' => $team->owner?->name,
            'stripe_customer_id' => $customerId,
            'stripe_subscription_id' => $subscriptionId,
            'stripe_customer_url' => $customerId === null ? null : "{$dashboard}/customers/{$customerId}",
            'stripe_subscription_url' => $subscriptionId === null ? null : "{$dashboard}/subscriptions/{$subscriptionId}",
            'seats_billed' => (int) $team->seats_billed,
            'retention_days' => $team->retention_days,
            'region' => $team->region,
            'release_version' => $team->release_version,
            'schema_version' => $team->schema_version,
            'provisioned_at' => $team->provisioned_at?->toIso8601String(),
            'provisioning_failed_step' => $team->provisioning_failed_step,
            'cancel_at_period_end' => (bool) $team->cancel_at_period_end,
            'current_period_end' => $team->current_period_end?->toIso8601String(),
            'deleted_at' => $team->deleted_at?->toIso8601String(),
            'created_at' => $team->created_at?->toIso8601String(),
        ];
    }

    /**
     * Which support actions the page offers for this tenant (phase 2). A
     * soft-deleted tenant refuses every action; reindex also needs indexing
     * to be turned on.
     *
     * @return array{
     *     reindex: array{enabled: bool},
     *     limits: array{enabled: bool},
     *     billing: array{enabled: bool, plans: list<string>, payment_statuses: list<string>},
     *     erase: array{enabled: bool}
     * }
     */
    public function actions(Team $team): array
    {
        $active = ! $team->trashed();

        return [
            'reindex' => ['enabled' => $active && (bool) config('indexing.enabled')],
            'limits' => ['enabled' => $active],
            'billing' => [
                'enabled' => $active,
                'plans' => array_map(fn (Plan $plan): string => $plan->value, Plan::cases()),
                'payment_statuses' => array_map(fn (PaymentStatus $status): string => $status->value, PaymentStatus::cases()),
            ],
            'erase' => ['enabled' => $active],
        ];
    }

    /**
     * The erase dry run this session holds for the tenant, as counts only, or
     * null when there is none, it is older than 15 minutes, or the tenant is
     * soft-deleted. Read from the session value the preview stored, so the
     * confirm step survives a reload and a refused erase.
     *
     * @return array{refused: bool, artifact_count: int, held: bool, generated_at: string}|null
     */
    public function erasurePlan(Team $team): ?array
    {
        if ($team->trashed()) {
            return null;
        }

        return EraseTenantAction::heldPreview($team)['plan'] ?? null;
    }

    /**
     * @return array{total: int, admins: list<array{name: string, email: string}>, pending_invitations: int}
     */
    public function members(Team $team): array
    {
        $admins = $team->members()
            ->wherePivot('role', TeamRole::Admin->value)
            ->orderBy('users.name')
            ->limit(self::ADMIN_LIMIT)
            ->get(['users.id', 'users.name', 'users.email'])
            ->map(fn (User $user): array => ['name' => $user->name, 'email' => $user->email])
            ->values()
            ->all();

        $pendingInvitations = $team->invitations()
            ->whereNull('accepted_at')
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->count();

        return [
            'total' => $team->memberships()->count(),
            'admins' => $admins,
            'pending_invitations' => $pendingInvitations,
        ];
    }

    /**
     * Org tokens have no last-used column, so theirs is always null.
     *
     * @return array{mcp: array{active: int, last_used_at: string|null}, org_tokens: array{active: int, last_used_at: null}}
     */
    public function connections(Team $team): array
    {
        $mcp = McpConnection::query()->where('team_id', $team->id)->active();
        $lastUsed = (clone $mcp)->max('last_used_at');

        $orgTokens = OrgToken::query()
            ->where('team_id', $team->id)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->count();

        return [
            'mcp' => [
                'active' => $mcp->count(),
                'last_used_at' => $lastUsed === null ? null : Carbon::parse($lastUsed)->toIso8601String(),
            ],
            'org_tokens' => ['active' => $orgTokens, 'last_used_at' => null],
        ];
    }

    /**
     * @return array{state: 'connected'|'none'|'unavailable', connections: list<array{type: string, name: string}>}
     */
    public function sso(Team $team): array
    {
        try {
            $connections = $this->polis->connections($team->slug);
        } catch (Throwable $exception) {
            report($exception);

            return ['state' => 'unavailable', 'connections' => []];
        }

        return [
            'state' => $connections === [] ? 'none' : 'connected',
            'connections' => $connections,
        ];
    }

    /**
     * Usage against the limits the Worker enforces. A Worker failure becomes
     * an error value, never an exception: this runs as a deferred prop and
     * must degrade only its own panel.
     *
     * @return array{storage_bytes: int, artifacts_this_period: int, period_start: string|null, period_end: string|null, limits: array{storage_bytes: int, artifacts_per_month: int}|null}|array{error: string}
     */
    public function usage(Team $team): array
    {
        try {
            $usage = $this->usage->currentUsage($team->slug);

            if (! isset($usage['storage_bytes'], $usage['artifacts_this_period'])) {
                throw new UnexpectedValueException('The Worker usage payload is missing storage_bytes or artifacts_this_period.');
            }

            return [
                'storage_bytes' => (int) $usage['storage_bytes'],
                'artifacts_this_period' => (int) $usage['artifacts_this_period'],
                'period_start' => $usage['period_start'] ?? null,
                'period_end' => $usage['period_end'] ?? null,
                'limits' => is_array($usage['limits'] ?? null) ? $usage['limits'] : null,
            ];
        } catch (Throwable $exception) {
            report($exception);

            return ['error' => 'the Worker could not be reached.'];
        }
    }

    /**
     * The tenant's latest audit events, newest first. Selects named columns
     * only, so no address, user agent or reason can reach the page, and
     * reads them without the model cast so an event type this build does not
     * know still lists (with no target) instead of failing the page.
     *
     * @return list<array{id: int, type: string, type_label: string, actor_label: string, target: string|null, outcome: string, at: string}>
     */
    public function events(Team $team): array
    {
        $rows = AuditEvent::query()
            ->where('team_id', $team->id)
            ->select(['id', 'event_type', 'actor', 'target', 'outcome', 'created_at'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::EVENT_LIMIT)
            ->toBase()
            ->get();

        $userIds = $rows->pluck('actor')
            ->filter(fn (mixed $actor): bool => is_string($actor) && ctype_digit($actor))
            ->unique()
            ->values();
        $names = User::query()->whereIn('id', $userIds)->pluck('name', 'id');

        return $rows->map(function (object $row) use ($names): array {
            $type = AuditEventType::tryFrom((string) $row->event_type);

            return [
                'id' => (int) $row->id,
                'type' => (string) $row->event_type,
                'type_label' => $type?->label() ?? (string) $row->event_type,
                'actor_label' => $this->actorLabel((string) $row->actor, $names),
                'target' => $this->targetFor((string) $row->event_type, $row->target),
                'outcome' => (string) $row->outcome,
                'at' => Carbon::parse($row->created_at)->toIso8601String(),
            ];
        })->values()->all();
    }

    /**
     * Whether the page may show the target of an event of this wire type.
     */
    public static function showsTargetFor(string $eventType): bool
    {
        foreach (self::TARGET_ALLOWLIST as $prefix) {
            if (str_starts_with($eventType, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function targetFor(string $eventType, mixed $target): ?string
    {
        if (! self::showsTargetFor($eventType) || ! is_string($target)) {
            return null;
        }

        return $target;
    }

    /**
     * @param  Collection<int|string, mixed>  $names
     */
    private function actorLabel(string $actor, $names): string
    {
        if ($actor === 'staff' || str_starts_with($actor, 'staff:')) {
            return 'artfct staff';
        }

        if (ctype_digit($actor)) {
            return (string) ($names[(int) $actor] ?? 'deleted user');
        }

        return $actor;
    }

    private function stripeDashboardBase(): string
    {
        $testMode = str_starts_with((string) config('services.stripe.secret'), 'sk_test');

        return $testMode ? 'https://dashboard.stripe.com/test' : 'https://dashboard.stripe.com';
    }
}
