<?php

namespace App\Http\Controllers;

use App\Contracts\ArtifactDirectory;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
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

        return Inertia::render('console/index', [
            'team' => $team,
            'artifacts' => $data['artifacts'],
            'filters' => $filters,
            'nextCursor' => $data['next_cursor'],
            'isAdmin' => $user->isAdminOf($team),
        ]);
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
