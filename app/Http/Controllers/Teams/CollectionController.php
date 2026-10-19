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

        // The directory is read with this member's own token, so the Worker
        // has already filtered out private artifacts they do not own. That one
        // read drives both the picker and the collection rows: an id the
        // directory does not list is not shown, so a collection that holds a
        // private artifact leaks neither its title nor a link to it.
        ['options' => $artifactOptions, 'visible' => $visibleArtifactIds] = $this->directoryArtifacts($artifacts, $team);

        return Inertia::render('teams/collections', [
            'artifactOptions' => $artifactOptions,
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
                ->map(function (Collection $collection) use ($team, $visibleArtifactIds): array {
                    $visibleArtifacts = $collection->artifacts
                        ->filter(fn ($artifact): bool => isset($visibleArtifactIds[(string) $artifact->artifact_id]))
                        ->values();

                    return [
                        'id' => $collection->id,
                        'name' => $collection->name,
                        'description' => $collection->description,
                        'canonical' => $collection->canonical,
                        'artifactIds' => $visibleArtifacts->pluck('artifact_id')->values(),
                        // Rows link the same way the console does: the app's own
                        // open route, which authorizes the viewer and is the only
                        // link that works for a secure artifact.
                        'openUrls' => $visibleArtifacts->mapWithKeys(fn ($artifact): array => [
                            $artifact->artifact_id => ArtifactViewLink::forArtifact($team->slug, $artifact->artifact_id, null),
                        ])->all(),
                        // Inert previews go through the app's session-authenticated
                        // preview route: it authorizes the viewer and reads the
                        // content server-side, so the page never needs the signing
                        // secret and the URL never becomes a shareable credential.
                        'previewUrls' => $visibleArtifacts->mapWithKeys(fn ($artifact): array => [
                            $artifact->artifact_id => route('teams.artifacts.preview', [
                                'team' => $team->slug,
                                'artifactId' => $artifact->artifact_id,
                            ]),
                        ])->all(),
                    ];
                }),
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
     * Every artifact this member may see, plus the picker's first hundred
     * options. The directory is called with the member's own token, so the
     * Worker has already filtered out private artifacts they do not own;
     * revoked artifacts are left out. A directory outage fails closed
     * (nothing visible) rather than showing whatever a stale page held.
     *
     * @return array{options: list<array{id: string, title: string}>, visible: array<string, true>}
     */
    private function directoryArtifacts(ArtifactDirectory $artifacts, Team $team): array
    {
        $options = [];
        $visible = [];
        $cursor = null;

        try {
            do {
                $page = $artifacts->listArtifacts($team->slug, [], $cursor, 200);

                foreach ($page['artifacts'] as $artifact) {
                    if (($artifact['revoked_at'] ?? null) !== null) {
                        continue;
                    }

                    $id = (string) $artifact['id'];
                    $visible[$id] = true;

                    if (count($options) < 100) {
                        $options[] = [
                            'id' => $id,
                            'title' => ($artifact['title'] ?? '') !== '' ? $artifact['title'] : substr($id, 0, 8),
                        ];
                    }
                }

                $cursor = $page['next_cursor'];
            } while ($cursor !== null);
        } catch (\Throwable $exception) {
            report($exception);

            return ['options' => [], 'visible' => []];
        }

        return ['options' => $options, 'visible' => $visible];
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
