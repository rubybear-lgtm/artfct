<?php

namespace App\Http\Controllers;

use App\Contracts\ArtifactContentSource;
use App\Contracts\ArtifactDirectory;
use App\Enums\AuditEventType;
use App\Models\Team;
use App\Models\User;
use App\Services\Artifacts\ArtifactAccessLink;
use App\Services\Artifacts\ArtifactIdShape;
use App\Services\Governance\AuditLogger;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * The artifact viewer (RUB-438): a thin header above one artifact running in a
 * sandboxed iframe on its isolated origin, with the sharing control.
 *
 * The route carries only an artifact id, so the owning team is resolved from
 * the signed-in member's own memberships — current team first. A team the
 * caller is not in is never asked about, and an artifact that resolves in no
 * team is the same 404 as one that does not exist, so the page is never an
 * existence oracle.
 *
 * The header is rendered by the app around the frame and is never injected
 * into the artifact's HTML. A non-public artifact needs a short-lived signed
 * link to be framed, and the mint is audited exactly as `ConsoleController::open`
 * does. A public artifact is served by the Worker to anyone, so it is framed
 * untokened and nothing is minted.
 */
final class ArtifactViewerController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * Show one artifact's viewer.
     */
    public function show(Request $request, string $artifactId, ArtifactDirectory $artifacts): InertiaResponse
    {
        abort_unless(ArtifactIdShape::isPermanent($artifactId), 404);

        $user = $request->user();
        [$team, $artifact] = $this->resolveArtifact($user, $artifactId, $artifacts);

        Gate::authorize('view', $team);

        $selectedVersion = $this->selectedVersion($request);

        // A `public` artifact is served by the Worker to anyone, so no token
        // is minted for it and nothing is audited. Every other artifact needs a
        // short-lived signed link to be framed.
        $sharing = $artifact['sharing'] ?? null;
        $links = ArtifactAccessLink::default();

        if ($sharing === 'public') {
            $frameUrl = $links->publicArtifactUrl($team->slug, $artifactId, $selectedVersion);
            $openUrl = $frameUrl;
        } else {
            $expiresAt = now()->addMinutes($links->ttlMinutes());
            $frameUrl = $links->forArtifact($team->slug, $artifactId, $expiresAt, $selectedVersion, (string) $user->id, $user->isAdminOf($team));

            abort_if($frameUrl === null, 503, 'Signed artifact links are not configured on this environment.');

            // `target` carries the artifact and the expiry and nothing else:
            // the token is a bearer credential, so it belongs in the frame URL
            // and nowhere near an append-only row that is read back on the
            // audit page and exported to SIEM.
            $this->auditLogger->recordForRequest(
                $request,
                AuditEventType::ArtifactLinkMinted,
                $team,
                (string) $user->id,
                "artifact:{$artifactId} expires:".$expiresAt->toIso8601String(),
            );

            $openUrl = route('console.open', array_filter([
                'team' => $team->slug,
                'artifactId' => $artifactId,
                'version' => $selectedVersion,
            ], fn ($value): bool => $value !== null));
        }

        $versionCount = (int) ($artifact['version_count'] ?? 1);

        $props = [
            'team' => [
                'slug' => $team->slug,
                'name' => $team->name,
                'publicSharingAllowed' => (bool) ($team->public_sharing_allowed ?? true),
            ],
            'artifact' => [
                'id' => $artifactId,
                'title' => $artifact['title'] ?? null,
                'description' => $artifact['description'] ?? null,
                'sharing' => $sharing,
                'editAccess' => $artifact['edit_access'] ?? null,
                'canEdit' => (bool) ($artifact['can_edit'] ?? false),
                'canChangeSharing' => (bool) ($artifact['can_change_sharing'] ?? false),
                'ownerName' => $this->ownerName($team, $artifact['owner_user_id'] ?? null),
                'updatedAt' => $artifact['updated_at'] ?? null,
                'version' => (int) ($artifact['version'] ?? 1),
                'versionCount' => $versionCount,
            ],
            'selectedVersion' => $selectedVersion,
            'frameUrl' => $frameUrl,
            'viewerUrl' => route('artifacts.show', ['artifactId' => $artifactId]),
            'openUrl' => $openUrl,
            'downloadUrl' => route('artifacts.download', ['artifactId' => $artifactId]),
        ];

        // The picker is only offered when there is more than one version to
        // choose between, so a single-version artifact costs no history call.
        if ($versionCount > 1) {
            $history = $artifacts->listVersions($team->slug, $artifactId);

            $props['versions'] = collect($history['versions'] ?? [])
                ->map(fn (array $version): array => [
                    'number' => (int) ($version['version'] ?? 0),
                    'created_at' => $version['created_at'] ?? null,
                    'current' => (bool) ($version['current'] ?? false),
                ])
                ->values()
                ->all();
        }

        return Inertia::render('artifacts/show', $props);
    }

    /**
     * Change who can open or edit an artifact. The Worker is the authority on
     * who may: the owner or a team admin, with `public` refused when the team
     * turned public links off. Every refusal is a plain toast and a redirect
     * back to the viewer, never a dead end.
     */
    public function updateSharing(Request $request, string $artifactId, ArtifactDirectory $artifacts): RedirectResponse
    {
        abort_unless(ArtifactIdShape::isPermanent($artifactId), 404);

        $user = $request->user();
        [$team, $artifact] = $this->resolveArtifact($user, $artifactId, $artifacts);

        Gate::authorize('view', $team);

        $validated = $request->validate([
            'sharing' => ['nullable', 'string', 'in:private,team,public'],
            'edit_access' => ['nullable', 'string', 'in:view,edit'],
        ]);

        $changes = array_filter([
            'sharing' => $validated['sharing'] ?? null,
            'edit_access' => $validated['edit_access'] ?? null,
        ], fn ($value): bool => $value !== null);

        $result = $artifacts->updateSharing($team->slug, $artifactId, $changes);

        if ($result['status'] === 'not_found') {
            abort(404);
        }

        [$type, $message] = match ($result['status']) {
            'updated' => ['success', 'Sharing updated.'],
            'forbidden' => ['error', 'Only the owner or a team admin can change sharing.'],
            'public_disabled' => ['error', 'Your team has turned off public links.'],
        };

        Inertia::flash('toast', ['type' => $type, 'message' => __($message)]);

        return redirect()->route('artifacts.show', ['artifactId' => $artifactId]);
    }

    /**
     * Download one artifact's entrypoint as an HTML attachment. The visible
     * check is `getArtifact` for the signed-in member first: the content read
     * itself uses a system credential that can see private artifacts, so it
     * must never be the thing that decides what the member may have.
     */
    public function download(Request $request, string $artifactId, ArtifactDirectory $artifacts, ArtifactContentSource $content): Response
    {
        abort_unless(ArtifactIdShape::isPermanent($artifactId), 404);

        $user = $request->user();
        [$team, $artifact] = $this->resolveArtifact($user, $artifactId, $artifacts);

        Gate::authorize('view', $team);

        $entry = $content->fetch($team->slug, $artifactId);

        abort_if($entry === null, 404);

        $filename = (Str::slug((string) ($artifact['title'] ?? '')) ?: $artifactId).'.html';

        return response($entry['html'], 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * The first of the caller's teams that has this artifact, current team
     * first. The Worker answers 404 for an artifact the caller cannot see,
     * including a private one, so a null from every team is the same 404 as a
     * missing artifact and the caller cannot tell the two apart.
     *
     * @return array{0: Team, 1: array<string, mixed>}
     */
    private function resolveArtifact(User $user, string $artifactId, ArtifactDirectory $artifacts): array
    {
        $teams = $user->teams()->orderBy('teams.id')->get();

        if ($user->current_team_id !== null) {
            $currentTeamId = $user->current_team_id;
            $teams = $teams->sortBy(fn (Team $team): int => $team->id === $currentTeamId ? 0 : 1)->values();
        }

        foreach ($teams as $team) {
            $artifact = $artifacts->getArtifact($team->slug, $artifactId);

            if ($artifact !== null) {
                return [$team, $artifact];
            }
        }

        abort(404);
    }

    /**
     * The version named by the `version` query, or null for the current one.
     * A malformed value is a 422 rather than a frame built for a version that
     * can only be refused.
     */
    private function selectedVersion(Request $request): ?int
    {
        $validator = Validator::make($request->query(), [
            'version' => ['nullable', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            abort(422, (string) $validator->errors()->first('version'));
        }

        $validated = $validator->validated();

        return isset($validated['version']) ? (int) $validated['version'] : null;
    }

    /**
     * The owning team member's display name for the artifact's owner, or null
     * when the owner has left the team or none was recorded.
     */
    private function ownerName(Team $team, mixed $ownerUserId): ?string
    {
        if (! is_string($ownerUserId) || $ownerUserId === '') {
            return null;
        }

        $name = $team->members()->where('users.id', $ownerUserId)->value('name');

        return is_string($name) ? $name : null;
    }
}
