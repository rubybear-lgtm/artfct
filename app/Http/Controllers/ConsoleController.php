<?php

namespace App\Http\Controllers;

use App\Contracts\ArtifactContentSource;
use App\Contracts\ArtifactDirectory;
use App\Enums\AuditEventType;
use App\Enums\TeamRole;
use App\Jobs\IndexArtifactJob;
use App\Models\ArtifactIndexEntry;
use App\Models\ArtifactIndexingFailure;
use App\Models\Collection;
use App\Models\Team;
use App\Models\User;
use App\Services\Artifacts\ArtifactAccessLink;
use App\Services\Governance\AuditLogger;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Admin console for managing artifacts in an organization.
 * Routes do not use EnsureTeamMembership; instead, they resolve
 * the team through the user's memberships to produce 404 for
 * cross-org access attempts (not 403).
 */
class ConsoleController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly ArtifactDirectory $artifacts,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * Show the artifact listing console.
     * Accessible to all team members (viewers see no revoke/export buttons).
     */
    public function index(Request $request, string $teamSlug)
    {
        $user = $request->user();
        $team = $this->resolveTeam($user, $teamSlug);

        Gate::authorize('view', $team);

        $filters = [
            'user_id' => $request->query('user_id'),
            'repo_url' => $request->query('repo_url'),
            'agent' => $request->query('agent'),
            'q' => $request->query('q'),
        ];

        $cursor = $request->query('cursor');
        $limit = 50;

        $data = $this->artifacts->listArtifacts($team->slug, $filters, $cursor, $limit);

        $artifactIds = collect($data['artifacts'])->pluck('id')->all();
        $indexed = ArtifactIndexEntry::query()->where('team_id', $team->id)->whereIn('artifact_id', $artifactIds)->pluck('artifact_id')->all();
        $failed = ArtifactIndexingFailure::query()->where('team_id', $team->id)->whereIn('artifact_id', $artifactIds)->pluck('artifact_id')->all();
        $indexingEnabled = (bool) config('indexing.enabled');

        return Inertia::render('console/index', [
            'collections' => Collection::query()->where('team_id', $team->id)->orderBy('name')->get(['id', 'name']),
            'canCollect' => $user->teamRole($team) !== TeamRole::Viewer,
            'indexingEnabled' => $indexingEnabled,
            'indexing' => collect($artifactIds)->mapWithKeys(fn (string $id): array => [
                $id => ! $indexingEnabled ? 'off' : (in_array($id, $indexed, true) ? 'indexed' : (in_array($id, $failed, true) ? 'failed' : 'pending')),
            ])->all(),
            'team' => $team,
            'artifacts' => $data['artifacts'],
            'filters' => $filters,
            'nextCursor' => $data['next_cursor'],
            'isAdmin' => $user->isAdminOf($team),
            // The signing secret belongs to the environment, not the member:
            // without one the page is not given an open control that could
            // only 503. The token itself never reaches props.
            'canOpenArtifacts' => ArtifactAccessLink::default()->configured(),
            // Spec 12 DoD: "parked in a dead-letter queue with the reason
            // recorded and surfaced in the console." The data is real and
            // tested; the page's own display of this list is a follow-up
            // (see DOCUMENTATION.md) — this session did not build the
            // React panel for it.
            'indexingFailures' => ArtifactIndexingFailure::query()
                ->where('team_id', $team->id)
                ->latest('failed_at')
                ->limit(20)
                ->get(['artifact_id', 'attempts', 'reason', 'failed_at']),
        ]);
    }

    /**
     * Queue a failed (or not yet indexed) artifact for indexing again. A
     * second click while the first is still queued is a no-op, and an
     * artifact that is already indexed is skipped.
     */
    public function reindex(Request $request, string $teamSlug, string $artifactId, ArtifactContentSource $content)
    {
        $user = $request->user();
        $team = $this->resolveTeam($user, $teamSlug);

        abort_if(! $user->isAdminOf($team), 403);
        abort_unless(config('indexing.enabled'), 409, 'Indexing is turned off.');

        $alreadyIndexed = ArtifactIndexEntry::query()->where('team_id', $team->id)->where('artifact_id', $artifactId)->exists();

        if (! $alreadyIndexed && Cache::add("reindex:{$team->id}:{$artifactId}", true, now()->addMinutes(10))) {
            $artifact = $content->fetch($team->slug, $artifactId);
            abort_if($artifact === null, 404);

            ArtifactIndexingFailure::query()->where('team_id', $team->id)->where('artifact_id', $artifactId)->delete();
            IndexArtifactJob::dispatch($team->id, $artifactId, $artifact['html'], $artifact['provenance'])->onQueue('indexing');
        }

        return redirect()->route('console.index', ['team' => $team])->with('message', 'Indexing queued.');
    }

    /**
     * Revoke an artifact (soft delete).
     * Admin only.
     */
    public function revoke(Request $request, string $teamSlug, string $artifactId)
    {
        $user = $request->user();
        $team = $this->resolveTeam($user, $teamSlug);

        abort_if(! $user->isAdminOf($team), 403);

        try {
            $artifact = $this->artifacts->revokeArtifact($team->slug, $artifactId);

            $this->auditLogger->recordForRequest($request, AuditEventType::ArtifactRevoked, $team, (string) $user->id, "artifact:{$artifactId}");

            return redirect()->route('console.index', ['team' => $team])
                ->with('message', 'Artifact revoked successfully.');
        } catch (\Exception $e) {
            if ($e->getCode() === 404) {
                abort(404);
            }
            throw $e;
        }
    }

    /**
     * Export all artifacts for the organization.
     * Admin only, rate-limited.
     */
    public function export(Request $request, string $teamSlug)
    {
        $user = $request->user();
        $team = $this->resolveTeam($user, $teamSlug);

        abort_if(! $user->isAdminOf($team), 403);

        // Rate limiting: 3 exports per hour per team
        $cacheKey = "console_export:{$team->id}";
        $exports = cache()->increment($cacheKey, 1);

        if ($exports === 1) {
            cache()->put($cacheKey, 1, now()->addHour());
        }

        if ($exports > 3) {
            abort(429, 'Too many export requests. Please try again later.');
        }

        try {
            $data = $this->artifacts->exportArtifacts($team->slug);

            return response()->json($data);
        } catch (\Exception $e) {
            if ($e->getCode() === 404) {
                abort(404);
            }
            if ($e->getCode() === 429) {
                abort(429, 'Rate limited');
            }
            throw $e;
        }
    }

    /**
     * Mint a short-lived signed link for one artifact and send the browser
     * (typically a new tab) to it at its isolated origin (spec 05).
     *
     * Any member who can see the artifact may open it. The link is bound to
     * this team's slug, so a member of another team is refused before anything
     * is minted, and an artifact that is not in this team's org is a 404
     * indistinguishable from a missing one — never an existence oracle for
     * another org's ids.
     */
    public function open(Request $request, string $teamSlug, string $artifactId, ArtifactContentSource $content)
    {
        $user = $request->user();
        $team = $this->resolveTeam($user, $teamSlug);

        Gate::authorize('view', $team);

        // Org-scoped existence check. A revoked artifact reads as absent here,
        // matching the Worker, which serves no content for it either.
        abort_if($content->fetch($team->slug, $artifactId) === null, 404);

        $url = ArtifactAccessLink::default()->forArtifact($team->slug, $artifactId);

        abort_if($url === null, 503, 'Signed artifact links are not configured on this environment.');

        return redirect()->away($url);
    }

    /**
     * Resolve team from the user's memberships only.
     * Returns 404 if user is not a member, not 403.
     */
    private function resolveTeam(User $user, string $teamSlug): Team
    {
        $team = $user->teams()
            ->where('slug', $teamSlug)
            ->firstOrFail();

        return $team;
    }
}
