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
use App\Services\Artifacts\ArtifactViewLink;
use App\Services\Artifacts\OrganizationExportArchive;
use App\Services\Governance\AuditLogger;
use App\Services\Indexing\IndexingService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

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
        private readonly IndexingService $indexer,
    ) {}

    /**
     * Show the artifact listing console.
     * Accessible to all team members (viewers see no revoke/export buttons).
     */
    public function index(Request $request, string $teamSlug): InertiaResponse
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
                $id => ! $indexingEnabled ? 'off' : (in_array($id, $failed, true) ? 'failed' : (in_array($id, $indexed, true) ? 'indexed' : 'pending')),
            ])->all(),
            'team' => $team,
            'artifacts' => $data['artifacts'],
            'filters' => $filters,
            'nextCursor' => $data['next_cursor'],
            'cursor' => is_string($cursor) ? $cursor : null,
            'isAdmin' => $user->isAdminOf($team),
            // The signing secret belongs to the environment, not the member:
            // without one the page is not given an open control. A secure row
            // would only 503, and the list the Worker returns carries no tier,
            // so the page cannot single out the public rows that would not
            // need the secret. The token itself never reaches props.
            'canOpenArtifacts' => ArtifactAccessLink::default()->configured(),
            // Spec 12 DoD: dead-letter reasons are visible in the console.
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
    public function reindex(Request $request, string $teamSlug, string $artifactId, ArtifactContentSource $content): RedirectResponse
    {
        $user = $request->user();
        $team = $this->resolveTeam($user, $teamSlug);

        abort_if(! $user->isAdminOf($team), 403);
        abort_unless(config('indexing.enabled'), 409, 'Indexing is turned off.');

        $alreadyIndexed = ArtifactIndexEntry::query()->where('team_id', $team->id)->where('artifact_id', $artifactId)->exists();
        $hasFailure = ArtifactIndexingFailure::query()->where('team_id', $team->id)->where('artifact_id', $artifactId)->exists();

        $reindexCacheKey = IndexArtifactJob::reindexCacheKey($team->id, $artifactId);
        if ((! $alreadyIndexed || $hasFailure) && Cache::add($reindexCacheKey, true, now()->addMinutes(10))) {
            try {
                $artifact = $content->fetch($team->slug, $artifactId);
                abort_if($artifact === null, 404);

                IndexArtifactJob::dispatch($team->id, $artifactId, $artifact['html'], $artifact['provenance'])->onQueue('indexing');
            } catch (Throwable $exception) {
                Cache::forget($reindexCacheKey);

                throw $exception;
            }
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Indexing queued.')]);

        return redirect()->route('console.index', ['team' => $team]);
    }

    /**
     * Revoke an artifact (soft delete).
     * Admin only.
     */
    public function revoke(Request $request, string $teamSlug, string $artifactId): RedirectResponse
    {
        $user = $request->user();
        $team = $this->resolveTeam($user, $teamSlug);

        abort_if(! $user->isAdminOf($team), 403);

        try {
            $artifact = $this->artifacts->revokeArtifact($team->slug, $artifactId);
            $this->indexer->removeFromIndex($team, $artifactId);

            $this->auditLogger->recordForRequest($request, AuditEventType::ArtifactRevoked, $team, (string) $user->id, "artifact:{$artifactId}");

            Inertia::flash('toast', ['type' => 'success', 'message' => __('Artifact revoked.')]);

            return redirect()->route('console.index', ['team' => $team]);
        } catch (\Exception $e) {
            if ($e->getCode() === 404) {
                abort(404);
            }
            throw $e;
        }
    }

    /**
     * Download all permanent artifacts for the organization as a zip
     * (metadata plus every file). Admin only, rate-limited, audited.
     */
    public function export(Request $request, string $teamSlug, OrganizationExportArchive $archive): BinaryFileResponse
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
            $path = $archive->build($team->slug);

            $this->auditLogger->recordForRequest($request, AuditEventType::ExportPerformed, $team, (string) $user->id, "team:{$team->slug}");

            return response()
                ->download($path, "{$team->slug}-artifacts-".now()->format('Y-m-d').'.zip', ['Content-Type' => 'application/zip'])
                ->deleteFileAfterSend();
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
     *
     * A `public` artifact needs none of that: the Worker serves its `/p/{id}`
     * URL to anyone, so the browser is sent straight there and no token is
     * minted (and so no mint is audited). Only a secure artifact is minted
     * for, and that mint is audited with the actor, the artifact and the
     * expiry — never the token itself.
     */
    public function open(Request $request, string $teamSlug, string $artifactId, ArtifactContentSource $content): RedirectResponse
    {
        $user = $request->user();
        $team = $this->resolveTeam($user, $teamSlug);

        Gate::authorize('view', $team);

        // Org-scoped existence check. A revoked artifact reads as absent here,
        // matching the Worker, which serves no content for it either.
        $artifact = $content->fetch($team->slug, $artifactId);

        abort_if($artifact === null, 404);

        if (ArtifactViewLink::isAnonymous($artifact['tier'] ?? null)) {
            return redirect()->away(ArtifactViewLink::publicPermanentUrl($artifactId));
        }

        $links = ArtifactAccessLink::default();
        $expiresAt = now()->addMinutes($links->ttlMinutes());
        $url = $links->forArtifact($team->slug, $artifactId, $expiresAt);

        abort_if($url === null, 503, 'Signed artifact links are not configured on this environment.');

        // `target` carries the artifact and the expiry and nothing else: the
        // token is a bearer credential for the artifact, so it belongs in the
        // redirect and nowhere near an append-only row that is read back on
        // the audit page and exported to SIEM.
        $this->auditLogger->recordForRequest(
            $request,
            AuditEventType::ArtifactLinkMinted,
            $team,
            (string) $user->id,
            "artifact:{$artifactId} expires:".$expiresAt->toIso8601String(),
        );

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
