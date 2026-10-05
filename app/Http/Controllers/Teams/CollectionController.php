<?php

namespace App\Http\Controllers\Teams;

use App\Contracts\ArtifactDirectory;
use App\Enums\TeamRole;
use App\Http\Controllers\Controller;
use App\Models\Collection;
use App\Models\Team;
use App\Services\Artifacts\ArtifactAccessLink;
use App\Services\Artifacts\ArtifactViewLink;
use App\Services\Collections\CollectionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Collections: any non-viewer member can create and curate them; pinning one
 * as the canonical set is admin-only (enforced in `CollectionService`).
 */
class CollectionController extends Controller
{
    public function index(Request $request, Team $team, ArtifactDirectory $artifacts): Response
    {
        $this->authorizeMember($request, $team);

        return Inertia::render('teams/collections', [
            // The picker offers the team's own artifacts by title, so a
            // curator never has to paste an id. Revoked artifacts are left
            // out; the directory returns the most recent page first.
            'artifactOptions' => $this->artifactOptions($artifacts, $team),
            'team' => ['slug' => $team->slug, 'name' => $team->name],
            'canEdit' => $this->canEdit($request, $team),
            'canPin' => $request->user()->can('pinCanonicalCollection', $team),
            // Same gate as the console and the search page: with no signing
            // secret the open route can only 503 for a secure artifact.
            'canOpenArtifacts' => ArtifactAccessLink::default()->configured(),
            'collections' => Collection::query()
                ->where('team_id', $team->id)
                ->with('artifacts')
                ->orderBy('name')
                ->get()
                ->map(fn (Collection $collection): array => [
                    'id' => $collection->id,
                    'name' => $collection->name,
                    'description' => $collection->description,
                    'canonical' => $collection->canonical,
                    'artifactIds' => $collection->artifacts->pluck('artifact_id')->values(),
                    // Rows link the same way the console does: the app's own
                    // open route, which authorizes the viewer and is the only
                    // link that works for a secure artifact.
                    'openUrls' => $collection->artifacts->mapWithKeys(fn ($artifact): array => [
                        $artifact->artifact_id => ArtifactViewLink::forArtifact($team->slug, $artifact->artifact_id, null),
                    ])->all(),
                    // Inert previews go through the app's session-authenticated
                    // preview route: it authorizes the viewer and reads the
                    // content server-side, so the page never needs the signing
                    // secret and the URL never becomes a shareable credential.
                    'previewUrls' => $collection->artifacts->mapWithKeys(fn ($artifact): array => [
                        $artifact->artifact_id => route('teams.artifacts.preview', [
                            'team' => $team->slug,
                            'artifactId' => $artifact->artifact_id,
                        ]),
                    ])->all(),
                ]),
        ]);
    }

    public function store(Request $request, Team $team, CollectionService $collections): RedirectResponse
    {
        $this->authorizeEditor($request, $team);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('collections', 'name')->where('team_id', $team->id)],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $collections->create($team, $request->user(), $validated['name'], $validated['description'] ?? null);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Collection created.')]);

        return back();
    }

    public function update(Request $request, Team $team, Collection $collection): RedirectResponse
    {
        $this->authorizeEditor($request, $team, $collection);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('collections', 'name')->where('team_id', $team->id)->ignore($collection->id)],
        ]);

        $collection->update(['name' => $validated['name']]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Collection renamed.')]);

        return back();
    }

    public function addArtifact(Request $request, Team $team, Collection $collection, CollectionService $collections): RedirectResponse
    {
        $this->authorizeEditor($request, $team, $collection);

        $validated = $request->validate(['artifact_id' => ['required', 'string', 'max:64']]);

        $collections->addArtifact($collection, $validated['artifact_id']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Added to :name.', ['name' => $collection->name])]);

        return back();
    }

    public function removeArtifact(Request $request, Team $team, Collection $collection, string $artifactId, CollectionService $collections): RedirectResponse
    {
        $this->authorizeEditor($request, $team, $collection);

        $collections->removeArtifact($collection, $artifactId);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Removed from :name.', ['name' => $collection->name])]);

        return back();
    }

    public function pin(Request $request, Team $team, Collection $collection, CollectionService $collections): RedirectResponse
    {
        $this->authorizeMember($request, $team);
        abort_unless($collection->team_id === $team->id, 404);

        $collections->pinCanonical($collection, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Pinned.')]);

        return back();
    }

    public function unpin(Request $request, Team $team, Collection $collection, CollectionService $collections): RedirectResponse
    {
        $this->authorizeMember($request, $team);
        abort_unless($collection->team_id === $team->id, 404);

        $collections->unpinCanonical($collection, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Unpinned.')]);

        return back();
    }

    /**
     * The team's recent artifacts by title for the picker, so a curator never
     * has to paste an id. A directory outage leaves the picker empty instead
     * of breaking the page.
     *
     * @return list<array{id: string, title: string}>
     */
    private function artifactOptions(ArtifactDirectory $artifacts, Team $team): array
    {
        try {
            $listed = $artifacts->listArtifacts($team->slug, [], null, 100)['artifacts'];
        } catch (\Throwable $exception) {
            report($exception);

            return [];
        }

        return collect($listed)
            ->reject(fn (array $artifact): bool => ($artifact['revoked_at'] ?? null) !== null)
            ->map(fn (array $artifact): array => [
                'id' => $artifact['id'],
                'title' => ($artifact['title'] ?? '') !== '' ? $artifact['title'] : substr($artifact['id'], 0, 8),
            ])
            ->values()
            ->all();
    }

    private function authorizeMember(Request $request, Team $team): void
    {
        abort_unless($request->user()->belongsToTeam($team), 404);
    }

    private function canEdit(Request $request, Team $team): bool
    {
        return $request->user()->teamRole($team) !== TeamRole::Viewer;
    }

    private function authorizeEditor(Request $request, Team $team, ?Collection $collection = null): void
    {
        $this->authorizeMember($request, $team);
        abort_unless($collection === null || $collection->team_id === $team->id, 404);
        abort_unless($this->canEdit($request, $team), 403);
    }
}
