<?php

namespace App\Services\Operator\Actions;

use App\Enums\AuditEventType;
use App\Models\Team;
use App\Models\User;
use App\Services\Governance\ErasureService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Tenant erasure from the operator console (spec 17), the one irreversible
 * action. It calls `ErasureService`, the same service `governance:erase`
 * calls, and always runs the dry run first:
 *
 * - `preview()` runs the dry run and remembers it in the session for
 *   `PREVIEW_MINUTES`, keyed by team id. It holds counts only, never
 *   artifact ids, and the tenant page derives its `erasure_plan` prop from
 *   it, so the confirm step survives a reload and a refused erase.
 * - `erase()` performs nothing unless that preview exists for this tenant,
 *   is fresh, and the typed slug matches exactly. Erases of one tenant are
 *   serialised by a lock.
 *
 * `ErasureService` hard-deletes artifacts; the team row, and so the audit
 * rows' `team_id`, is not touched. The service writes its own
 * `artifact.deleted` row (actor `staff:{id}`); the operator rows are written
 * in addition, with an internal-only `operator.erase_started` row before the
 * irreversible call.
 */
final class EraseTenantAction
{
    public const PREVIEW_MINUTES = 15;

    public const LOCK_SECONDS = 120;

    public function __construct(
        private readonly OperatorActionRecorder $recorder,
        private readonly ErasureService $erasure,
    ) {}

    public static function previewKey(Team $team): string
    {
        return 'ops_erase_preview.'.$team->id;
    }

    /**
     * The dry run held for this tenant, or null when there is none, it
     * belongs to another tenant, or it is older than `PREVIEW_MINUTES`.
     *
     * @return array{slug: string, at: int, team_id: int, plan: array{refused: bool, artifact_count: int, held: bool, generated_at: string}}|null
     */
    public static function heldPreview(Team $team): ?array
    {
        $preview = session()->get(self::previewKey($team));

        if (
            ! is_array($preview)
            || ($preview['team_id'] ?? null) !== $team->id
            || ($preview['slug'] ?? null) !== $team->slug
            || ! is_int($preview['at'] ?? null)
            || ! is_array($preview['plan'] ?? null)
            || $preview['at'] < now()->subMinutes(self::PREVIEW_MINUTES)->timestamp
        ) {
            return null;
        }

        return $preview;
    }

    /**
     * Runs the dry run and stores it in the session.
     *
     * @return array{refused: bool, artifact_count: int, held: bool, generated_at: string}
     */
    public function preview(Request $request, Team $team): array
    {
        // A preview is read-only and carries no reason, so it is not logged
        // here; `ErasureService` writes its own dry-run row, as it does for
        // the CLI.
        if ($team->trashed()) {
            throw ValidationException::withMessages(['action' => 'This tenant is deleted. No action can run against it.']);
        }

        try {
            $plan = $this->erasure->erase($team, dryRun: true, actor: $this->actor($request));
        } catch (Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages(['action' => 'The dry run could not be completed.']);
        }

        $summary = [
            'refused' => $plan->refused,
            'artifact_count' => count($plan->artifactIds),
            'held' => $plan->refused,
            'generated_at' => now()->toIso8601String(),
        ];

        $request->session()->put(self::previewKey($team), [
            'slug' => $team->slug,
            'at' => now()->timestamp,
            'team_id' => $team->id,
            'plan' => $summary,
        ]);

        return $summary;
    }

    public function erase(Request $request, Team $team, string $confirmSlug, string $reason): string
    {
        $type = AuditEventType::OperatorTenantErased;
        $key = self::previewKey($team);

        $this->recorder->refuseIfDeleted($request, $type, $team, $team->slug, $reason);

        $stored = $request->session()->get($key);
        if (! is_array($stored) || ($stored['team_id'] ?? null) !== $team->id || ($stored['slug'] ?? null) !== $team->slug || ! is_int($stored['at'] ?? null)) {
            $this->recorder->refuse($request, $type, $team, $team->slug, $reason, 'action', 'Run the dry run first.');
        }

        if ($stored['at'] < now()->subMinutes(self::PREVIEW_MINUTES)->timestamp) {
            $request->session()->forget($key);

            $this->recorder->refuse($request, $type, $team, $team->slug, $reason, 'action', 'The dry run is out of date. Run it again.');
        }

        if ($confirmSlug !== $team->slug) {
            $this->recorder->refuse($request, $type, $team, $team->slug, $reason, 'confirm_slug', 'Type the team slug exactly to confirm.');
        }

        $lock = Cache::lock("ops:erase:{$team->id}", self::LOCK_SECONDS);
        if (! $lock->get()) {
            $this->recorder->refuse($request, $type, $team, $team->slug, $reason, 'action', 'An erase is already running for this tenant.');
        }

        try {
            // The dry run is consumed by the attempt, whatever its outcome.
            $request->session()->forget($key);

            $this->recorder->begin($request, AuditEventType::OperatorEraseStarted, $team, $team->slug, $reason);

            try {
                $plan = $this->erasure->erase($team, dryRun: false, actor: $this->actor($request));
            } catch (Throwable $exception) {
                report($exception);

                $this->recorder->refuse($request, $type, $team, $team->slug, $reason, 'action', 'The erasure stopped before it finished. Run the dry run again before retrying.');
            }

            if ($plan->refused) {
                $this->recorder->refuse($request, $type, $team, $team->slug, $reason, 'action', 'Refused: an artifact is under legal hold.');
            }

            $this->recorder->succeed($request, $type, $team, $team->slug, $reason);
        } finally {
            $lock->release();
        }

        return 'Tenant erased.';
    }

    private function actor(Request $request): string
    {
        /** @var User $user */
        $user = $request->user();

        return "staff:{$user->id}";
    }
}
