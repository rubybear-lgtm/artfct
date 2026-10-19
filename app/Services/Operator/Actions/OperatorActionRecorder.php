<?php

namespace App\Services\Operator\Actions;

use App\Enums\AuditEventType;
use App\Models\Team;
use App\Services\Operator\OperatorAuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * What every operator action shares (spec 17): the reason rules, the
 * refusal of a soft-deleted tenant, and the pair of outcomes. A guard
 * rejection records the same event type with outcome `failure` and the
 * supplied reason, changes nothing, and answers with a validation error.
 *
 * Every event goes through `OperatorAuditLogger`, which never suppresses a
 * mutating event, so the tenant's admins see each action the same minute.
 */
final class OperatorActionRecorder
{
    /** @var list<string> */
    public const REASON_RULES = ['required', 'string', 'min:5', 'max:500'];

    public function __construct(private readonly OperatorAuditLogger $audit) {}

    /**
     * The trimmed reason from an already validated request.
     */
    public function reasonFrom(Request $request): string
    {
        return trim((string) $request->input('reason'));
    }

    /**
     * Records the action as a success.
     */
    public function succeed(Request $request, AuditEventType $type, Team $team, string $target, string $reason): void
    {
        $this->audit->recordForRequest($request, $type, $team, $target, $reason, 'success');
    }

    /**
     * Records that an irreversible step is about to run, in the internal log
     * only, so a crash part-way still leaves an operator row.
     */
    public function begin(Request $request, AuditEventType $type, Team $team, string $target, string $reason): void
    {
        $this->audit->recordForRequest($request, $type, $team, $target, $reason, 'started');
    }

    /**
     * Records the action as a failure and answers with a validation error.
     *
     * @throws ValidationException always.
     */
    public function refuse(
        Request $request,
        AuditEventType $type,
        Team $team,
        string $target,
        string $reason,
        string $field,
        string $message,
    ): never {
        $this->audit->recordForRequest($request, $type, $team, $target, $reason, 'failure');

        throw ValidationException::withMessages([$field => $message]);
    }

    /**
     * A soft-deleted tenant refuses every action.
     *
     * @throws ValidationException when the tenant is soft-deleted.
     */
    public function refuseIfDeleted(Request $request, AuditEventType $type, Team $team, string $target, string $reason): void
    {
        if ($team->trashed()) {
            $this->refuse($request, $type, $team, $target, $reason, 'action', 'This tenant is deleted. No action can run against it.');
        }
    }
}
