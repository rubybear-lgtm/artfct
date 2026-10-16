<?php

namespace App\Services\Operator;

use App\Enums\AuditEventType;
use App\Models\AuditEvent;
use App\Models\OperatorAuditEvent;
use App\Models\OperatorSuppressionCase;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates, renews and releases suppression cases (spec 17).
 *
 * Only a staff member who also holds `case_manager_at` may change a case. A
 * refusal is recorded in the internal operator log as `operator.case_denied`
 * and never reaches a tenant. Every case change is recorded in the internal
 * log only, so a tenant cannot learn from its own audit page that a case
 * exists.
 */
final class SuppressionCaseService
{
    public function __construct(private readonly OperatorAuditLogger $logger) {}

    /**
     * @throws AuthorizationException when the user is not a case manager.
     * @throws ValidationException when the category, reference or expiry is missing or out of range, or the tenant already has an active case.
     */
    public function create(
        User $actor,
        Team $team,
        ?string $category,
        ?string $reference,
        ?CarbonInterface $expiresAt,
        string $ip = 'internal',
        string $userAgent = 'operator',
    ): OperatorSuppressionCase {
        $this->authorize($actor, $team, 'create', $reference, $ip, $userAgent);

        $reference = $this->validated($category, $reference, $expiresAt);

        $hasActiveCase = OperatorSuppressionCase::query()->active()->where('team_id', $team->id)->exists();

        if ($hasActiveCase) {
            throw ValidationException::withMessages([
                'case' => 'This tenant already has an active suppression case. Renew it instead.',
            ]);
        }

        $case = OperatorSuppressionCase::create([
            'team_id' => $team->id,
            'category' => $category,
            'reference' => $reference,
            'created_by_user_id' => $actor->id,
            'expires_at' => $expiresAt,
        ]);

        $this->logger->record(
            AuditEventType::OperatorCaseCreated,
            $actor,
            $team,
            "case:{$case->id}",
            $ip,
            $userAgent,
            "{$category} {$reference}, expires {$case->expires_at->toIso8601String()}",
        );

        return $case;
    }

    /**
     * Starts a new window of at most 90 days from now, under a new reference.
     * A case that has already expired or been released cannot be renewed:
     * open a new one.
     *
     * @throws AuthorizationException when the user is not a case manager.
     * @throws ValidationException when the reference or expiry is invalid or the case is no longer active.
     */
    public function renew(
        User $actor,
        OperatorSuppressionCase $case,
        ?string $reference,
        ?CarbonInterface $expiresAt,
        string $ip = 'internal',
        string $userAgent = 'operator',
    ): OperatorSuppressionCase {
        $team = Team::query()->find($case->team_id);

        $this->authorize($actor, $team, 'renew', $reference, $ip, $userAgent, $case);

        $reference = $this->validated($case->category, $reference, $expiresAt);

        if (! $case->isActive()) {
            throw ValidationException::withMessages([
                'case' => 'Only an active case can be renewed.',
            ]);
        }

        if ($reference === $case->reference) {
            throw ValidationException::withMessages([
                'reference' => 'Renewing needs a new reference.',
            ]);
        }

        $previousReference = $case->reference;

        $case->update([
            'reference' => $reference,
            'expires_at' => $expiresAt,
        ]);

        $this->logger->record(
            AuditEventType::OperatorCaseRenewed,
            $actor,
            $team,
            "case:{$case->id}",
            $ip,
            $userAgent,
            "{$previousReference} to {$reference}, expires {$case->expires_at->toIso8601String()}",
        );

        return $case;
    }

    /**
     * Ends a case now and writes its withheld events to the tenant's log.
     *
     * @throws AuthorizationException when the user is not a case manager.
     * @throws ValidationException when the case was already released.
     */
    public function release(
        User $actor,
        OperatorSuppressionCase $case,
        string $ip = 'internal',
        string $userAgent = 'operator',
    ): OperatorSuppressionCase {
        $team = Team::query()->find($case->team_id);

        $this->authorize($actor, $team, 'release', $case->reference, $ip, $userAgent, $case);

        if ($case->released_at !== null) {
            throw ValidationException::withMessages([
                'case' => 'This case is already released.',
            ]);
        }

        $written = $this->publishWithheldEvents($case);

        if ($written !== null) {
            $this->logger->record(
                AuditEventType::OperatorCaseReleased,
                $actor,
                $team,
                "case:{$case->id}",
                $ip,
                $userAgent,
                "released by case manager, reference {$case->reference}",
            );
        }

        return $case->refresh();
    }

    /**
     * Writes a finished case's withheld events to the tenant's log with their
     * original timestamps, then marks the case released, in one transaction.
     * Running it again for the same case writes nothing. Returns the number
     * of events written, or null when the case was already released (so the
     * caller does not log a second release).
     */
    public function publishWithheldEvents(OperatorSuppressionCase $case): ?int
    {
        return DB::transaction(function () use ($case): ?int {
            $locked = OperatorSuppressionCase::query()->lockForUpdate()->find($case->id);

            if ($locked === null || $locked->released_at !== null) {
                return null;
            }

            $teamExists = Team::query()->whereKey($locked->team_id)->exists();
            $written = 0;

            if ($teamExists) {
                OperatorAuditEvent::query()
                    ->where('suppression_case_id', $locked->id)
                    ->orderBy('created_at')
                    ->orderBy('id')
                    ->each(function (OperatorAuditEvent $withheld) use (&$written): void {
                        $event = new AuditEvent([
                            'team_id' => $withheld->team_id,
                            'event_type' => $withheld->event_type,
                            'actor' => $withheld->staff_user_id === null ? 'staff' : "staff:{$withheld->staff_user_id}",
                            'target' => $withheld->target,
                            'ip' => OperatorAuditLogger::TENANT_COPY_IP,
                            'user_agent' => OperatorAuditLogger::TENANT_COPY_USER_AGENT,
                            'outcome' => $withheld->outcome,
                            'reason' => $withheld->reason,
                        ]);
                        $event->created_at = $withheld->created_at;
                        $event->save();

                        $written++;
                    });
            }

            $locked->update(['released_at' => now()]);

            return $written;
        });
    }

    /**
     * @throws AuthorizationException
     */
    private function authorize(
        User $actor,
        ?Team $team,
        string $action,
        ?string $reference,
        string $ip,
        string $userAgent,
        ?OperatorSuppressionCase $case = null,
    ): void {
        if ($actor->isCaseManager()) {
            return;
        }

        $this->logger->record(
            AuditEventType::OperatorCaseDenied,
            $actor,
            $team,
            $case === null ? "case:{$action}" : "case:{$case->id}",
            $ip,
            $userAgent,
            "refused {$action}, not a case manager".($reference === null ? '' : ", reference {$reference}"),
            'denied',
        );

        throw new AuthorizationException('Only a case manager may create, renew or release a suppression case.');
    }

    /**
     * Returns the trimmed reference once category, reference and expiry are
     * all valid. Nothing is created when any of them fails.
     *
     * @throws ValidationException
     */
    private function validated(?string $category, ?string $reference, ?CarbonInterface $expiresAt): string
    {
        $errors = [];

        if ($category === null || ! in_array($category, OperatorSuppressionCase::CATEGORIES, true)) {
            $errors['category'] = 'Choose legal_request or abuse_investigation.';
        }

        $reference = trim((string) $reference);

        if ($reference === '') {
            $errors['reference'] = 'A ticket or order reference is required.';
        }

        if ($expiresAt === null) {
            $errors['expires_at'] = 'An expiry is required.';
        } elseif (! $expiresAt->isFuture()) {
            $errors['expires_at'] = 'The expiry must be in the future.';
        } elseif ($expiresAt->greaterThan(now()->addDays(OperatorSuppressionCase::MAX_DURATION_DAYS))) {
            $errors['expires_at'] = 'A case may not run longer than '.OperatorSuppressionCase::MAX_DURATION_DAYS.' days.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $reference;
    }
}
