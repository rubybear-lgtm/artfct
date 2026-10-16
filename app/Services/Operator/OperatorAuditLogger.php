<?php

namespace App\Services\Operator;

use App\Enums\AuditEventType;
use App\Models\OperatorAuditEvent;
use App\Models\OperatorSuppressionCase;
use App\Models\Team;
use App\Models\User;
use App\Services\Governance\AuditLogger;
use App\Support\ClientIp;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only way the operator console records anything (spec 17).
 *
 * Every event is written to the internal operator log first, at full
 * fidelity. The tenant's own copy is then written through the existing
 * `AuditLogger`, unless a suppression applies. Suppression is "never write
 * the tenant copy", not "hide a row", so no tenant-facing read path has a
 * filter that could be forgotten.
 *
 * A suppression applies only when all of these hold: the event is an operator
 * read, the tenant has an active case, and nothing else. There is no
 * per-event flag: a caller cannot ask for one event to be withheld.
 */
final class OperatorAuditLogger
{
    /** A staff user's repeat views of one tenant inside this window log once. */
    public const VIEW_DEDUPE_MINUTES = 60;

    /**
     * What a tenant's own copy of an operator event carries for the address
     * and browser. The real values stay in the internal log: a staff member's
     * IP is stable per person, so showing it would name them.
     */
    public const TENANT_COPY_IP = 'artfct staff';

    public const TENANT_COPY_USER_AGENT = 'artfct staff';

    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * Record one operator event. Returns the internal log row.
     *
     * @throws ValidationException when a mutating action has no reason.
     */
    public function record(
        AuditEventType $type,
        ?User $staff,
        ?Team $team,
        string $target,
        string $ip,
        string $userAgent,
        ?string $reason = null,
        string $outcome = 'success',
    ): OperatorAuditEvent {
        $reason = $reason === null ? null : trim($reason);

        if ($type->isOperatorMutation() && ($reason === null || $reason === '')) {
            throw ValidationException::withMessages([
                'reason' => 'A reason is required for this action.',
            ]);
        }

        // One transaction for both writes: a failure of the tenant copy must
        // not leave an internal row marked as written to the tenant.
        return DB::transaction(function () use ($type, $staff, $team, $target, $ip, $userAgent, $reason, $outcome): OperatorAuditEvent {
            $case = null;

            if ($type->isOperatorRead() && $team !== null) {
                // Locked so a release cannot flush between this check and the
                // insert, which would strand a withheld row on a released case.
                $case = OperatorSuppressionCase::query()
                    ->active()
                    ->where('team_id', $team->id)
                    ->lockForUpdate()
                    ->first();
            }

            $row = new OperatorAuditEvent([
                'team_id' => $team?->id,
                'team_slug' => $team?->slug,
                'event_type' => $type,
                'staff_user_id' => $staff?->id,
                'target' => $target,
                'reason' => $reason,
                'ip' => $ip,
                'user_agent' => $userAgent,
                'outcome' => $outcome,
                'suppression_case_id' => $case?->id,
            ]);
            $row->created_at = Carbon::now();
            $row->save();

            if ($row->suppression_case_id === null && ! $type->isInternalOnly()) {
                $this->auditLogger->record(
                    $type,
                    $team,
                    $staff === null ? 'staff' : "staff:{$staff->id}",
                    $target,
                    self::TENANT_COPY_IP,
                    self::TENANT_COPY_USER_AGENT,
                    $outcome,
                    $reason,
                );
            }

            return $row;
        });
    }

    /**
     * Same as `record()`, taking the client address and user agent from the
     * request.
     */
    public function recordForRequest(
        Request $request,
        AuditEventType $type,
        ?Team $team,
        string $target,
        ?string $reason = null,
        string $outcome = 'success',
    ): OperatorAuditEvent {
        /** @var User|null $staff */
        $staff = $request->user();

        return $this->record(
            $type,
            $staff,
            $team,
            $target,
            (string) ClientIp::for($request),
            $request->userAgent() ?? 'unknown',
            $reason,
            $outcome,
        );
    }

    /**
     * Record an operator-initiated artisan run (spec 17): the same event the
     * console writes for the same action, with no staff user and the address
     * and browser of a command line. Routine runs (the hourly limits sync)
     * pass no reason and are not recorded; only a run that names a reason is
     * an operator action.
     */
    public function recordForCli(
        AuditEventType $type,
        ?Team $team,
        string $target,
        string $reason,
        string $outcome = 'success',
    ): OperatorAuditEvent {
        return $this->record($type, null, $team, $target, 'cli', 'artisan', $reason, $outcome);
    }

    /**
     * Whether a reason given on the command line meets the console's rules
     * (5 to 500 characters once trimmed).
     */
    public static function isValidReason(?string $reason): bool
    {
        $length = mb_strlen(trim((string) $reason));

        return $length >= 5 && $length <= 500;
    }

    /**
     * Record a tenant detail page view, at most once per staff user and
     * tenant per hour. The dedupe reads the internal log, so a suppressed view
     * counts toward the hour as well. Returns null when the view was deduped.
     */
    public function recordTenantView(
        User $staff,
        Team $team,
        string $ip,
        string $userAgent,
    ): ?OperatorAuditEvent {
        return DB::transaction(function () use ($staff, $team, $ip, $userAgent): ?OperatorAuditEvent {
            // Serialises concurrent views by one staff user so the
            // check-then-insert below cannot log twice.
            User::query()->whereKey($staff->id)->lockForUpdate()->first();

            $alreadyLogged = OperatorAuditEvent::query()
                ->where('event_type', AuditEventType::OperatorTenantViewed)
                ->where('staff_user_id', $staff->id)
                ->where('team_id', $team->id)
                ->where('created_at', '>', now()->subMinutes(self::VIEW_DEDUPE_MINUTES))
                ->exists();

            if ($alreadyLogged) {
                return null;
            }

            return $this->record(
                AuditEventType::OperatorTenantViewed,
                $staff,
                $team,
                $team->slug,
                $ip,
                $userAgent,
            );
        });
    }

    /**
     * Request-based wrapper around `recordTenantView()`.
     */
    public function recordTenantViewForRequest(Request $request, Team $team): ?OperatorAuditEvent
    {
        /** @var User $staff */
        $staff = $request->user();

        return $this->recordTenantView(
            $staff,
            $team,
            (string) ClientIp::for($request),
            $request->userAgent() ?? 'unknown',
        );
    }
}
