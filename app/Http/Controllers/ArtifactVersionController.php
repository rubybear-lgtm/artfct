<?php

namespace App\Http\Controllers;

use App\Contracts\ArtifactDirectory;
use App\Enums\AuditEventType;
use App\Models\Team;
use App\Models\User;
use App\Services\Artifacts\ArtifactIdShape;
use App\Services\Governance\AuditLogger;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The console's version history for one permanent artifact: list every
 * completed version, open any one of them, restore a past one.
 *
 * Membership is resolved from the caller's own teams before the Worker is
 * asked for anything, exactly like `ConsoleController`: a team the caller is
 * not in is one that does not exist (404), never a 403 that would confirm it.
 * A non-permanent id is the same 404, so the page is never an existence oracle
 * and a stray/legacy link cannot probe the Worker.
 */
final class ArtifactVersionController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * Show one artifact's version history, newest first. The Worker answers
     * another organization's artifact with the same 404 as a missing one, so
     * this page cannot tell them apart either.
     */
    public function show(Request $request, string $teamSlug, string $artifactId, ArtifactDirectory $artifacts): Response
    {
        $team = $this->resolveTeam($request->user(), $teamSlug);

        Gate::authorize('view', $team);

        abort_unless(ArtifactIdShape::isPermanent($artifactId), 404);

        $history = $artifacts->listVersions($team->slug, $artifactId);

        abort_if($history === null, 404);

        $memberNames = $this->memberNames($team);
        $versions = collect($history['versions'])
            ->map(fn (array $version): array => [
                'number' => (int) ($version['version'] ?? 0),
                'created_at' => $version['created_at'] ?? null,
                'created_by' => $memberNames[(string) ($version['created_by'] ?? '')] ?? null,
                'agent' => $version['agent'] ?? null,
                'title' => $version['title'] ?? null,
                'current' => (bool) ($version['current'] ?? false),
                'restored_from' => $version['restored_from'] ?? null,
            ])
            ->values()
            ->all();

        return Inertia::render('console/artifact-versions', [
            'team' => ['slug' => $team->slug, 'name' => $team->name],
            'artifactId' => $artifactId,
            // The newest version names the artifact, matching the console list.
            'title' => $versions[0]['title'] ?? null,
            'currentVersion' => $history['current_version'],
            'versions' => $versions,
        ]);
    }

    /**
     * Publish a past version as the current one. Only the owner may (the
     * Worker enforces it and answers 403); every refusal is a plain toast and
     * a redirect back, never a dead end.
     */
    public function restore(Request $request, string $teamSlug, string $artifactId, int $version, ArtifactDirectory $artifacts): RedirectResponse
    {
        $team = $this->resolveTeam($request->user(), $teamSlug);

        Gate::authorize('view', $team);

        abort_unless(ArtifactIdShape::isPermanent($artifactId), 404);
        abort_unless($version >= 1, 404);

        $result = $artifacts->restoreVersion($team->slug, $artifactId, $version);

        return match ($result['status']) {
            'restored' => $this->restored($request, $team, $artifactId, $version),
            'unchanged' => $this->redirectToHistory($team, $artifactId, 'That version is already current.', 'success'),
            'forbidden' => $this->redirectToHistory($team, $artifactId, 'Only the owner can restore this artifact.', 'error'),
            'conflict' => $this->redirectToHistory($team, $artifactId, 'Someone else published a version at the same moment. Try again.', 'error'),
            'not_found' => $this->redirectToHistory($team, $artifactId, 'That version was not found.', 'error'),
        };
    }

    private function restored(Request $request, Team $team, string $artifactId, int $version): RedirectResponse
    {
        $this->auditLogger->recordForRequest(
            $request,
            AuditEventType::ArtifactVersionRestored,
            $team,
            (string) $request->user()->id,
            "artifact:{$artifactId} version:{$version}",
        );

        return $this->redirectToHistory($team, $artifactId, "Restored version {$version}.", 'success');
    }

    private function redirectToHistory(Team $team, string $artifactId, string $message, string $type): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => __($message)]);

        return to_route('console.versions', ['team' => $team->slug, 'artifactId' => $artifactId]);
    }

    /**
     * Display names for the team's members, keyed by user id, so a version row
     * can name its author. A publisher who has since left the team — or a
     * version published before owners were recorded — has no name here.
     *
     * @return array<string, string>
     */
    private function memberNames(Team $team): array
    {
        return $team->members()
            ->pluck('name', 'users.id')
            ->mapWithKeys(fn ($name, $id): array => [(string) $id => (string) $name])
            ->all();
    }

    /**
     * Resolve the team from the user's memberships only, exactly like the
     * console: a non-member gets 404, not 403.
     */
    private function resolveTeam(User $user, string $teamSlug): Team
    {
        return $user->teams()
            ->where('slug', $teamSlug)
            ->firstOrFail();
    }
}
