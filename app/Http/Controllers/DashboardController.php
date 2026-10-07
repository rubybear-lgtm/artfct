<?php

namespace App\Http\Controllers;

use App\Contracts\ArtifactDirectory;
use App\Enums\Plan;
use App\Models\Collection;
use App\Models\CollectionArtifact;
use App\Models\McpConnection;
use App\Models\OrgToken;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Services\Artifacts\ArtifactAccessLink;
use App\Services\Artifacts\ArtifactViewLink;
use App\Services\Search\SearchResult;
use App\Services\Search\SearchService;
use App\Support\ClientIp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(
        private readonly ArtifactDirectory $artifacts,
        private readonly SearchService $search,
    ) {}

    public function __invoke(Request $request): Response|RedirectResponse
    {
        if ($request->user()->needsFirstTeam()) {
            return redirect()->route('onboarding.team.show');
        }

        $email = strtolower($request->user()->email);

        $pendingInvitations = TeamInvitation::query()
            ->with(['inviter', 'team'])
            ->whereRaw('LOWER(email) = ?', [$email])
            ->whereNull('accepted_at')
            ->where(fn ($query) => $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>=', now()))
            ->latest()
            ->get()
            ->map(fn (TeamInvitation $invitation) => [
                'code' => $invitation->code,
                'inviterName' => $invitation->inviter->name,
                'team' => [
                    'name' => $invitation->team->name,
                    'slug' => $invitation->team->slug,
                ],
            ]);

        return Inertia::render('dashboard', [
            'pendingInvitations' => $pendingInvitations,
            'setup' => $this->setupProgress($request),
            'home' => $this->home($request),
        ]);
    }

    /**
     * Which first-run steps the current team has completed, or null when the
     * user has no current team yet.
     *
     * @return array{invitedTeammates: bool, createdToken: bool, connectedMcp: bool, choseAPlan: bool}|null
     */
    private function setupProgress(Request $request): ?array
    {
        $team = $request->user()->currentTeam;

        if ($team === null) {
            return null;
        }

        return [
            'invitedTeammates' => $team->memberships()->count() > 1 || $team->invitations()->exists(),
            'createdToken' => OrgToken::query()->where('team_id', $team->id)->exists(),
            'connectedMcp' => McpConnection::query()->where('team_id', $team->id)->active()->exists(),
            'choseAPlan' => ($team->plan ?? Plan::Free) !== Plan::Free,
        ];
    }

    /**
     * The dashboard's home section: team search, recent artifacts and pinned
     * collections. Null when the user has no current team.
     *
     * @return array{
     *     team: array{slug: string, name: string},
     *     indexingEnabled: bool,
     *     canOpenArtifacts: bool,
     *     filters: array{q: string, collection: string, since: string, scope: string},
     *     searched: bool,
     *     results: list<array<string, mixed>>,
     *     searchError: string|null,
     *     recent: list<array<string, mixed>>,
     *     recentError: string|null,
     *     collections: list<array<string, mixed>>,
     *     collectionCount: int,
     *     filterCollections: list<array<string, mixed>>
     * }|null
     */
    private function home(Request $request): ?array
    {
        $team = $request->user()->currentTeam;

        if ($team === null) {
            return null;
        }

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:500'],
            'collection' => ['nullable', 'string', 'max:255'],
            'since' => ['nullable', 'date'],
            'scope' => ['nullable', 'in:team,mine'],
        ]);

        $query = trim((string) ($filters['q'] ?? ''));
        $scope = ($filters['scope'] ?? null) === 'mine' ? 'mine' : 'team';
        $indexingEnabled = (bool) config('indexing.enabled');
        $searched = $indexingEnabled && $query !== '';

        $results = [];
        $searchError = null;

        if ($searched) {
            try {
                $canonicalIds = CollectionArtifact::query()
                    ->whereIn('collection_id', Collection::query()->where('team_id', $team->id)->where('canonical', true)->select('id'))
                    ->pluck('artifact_id')
                    ->all();

                $results = $this->searchResults($team, $this->search->search(
                    $team,
                    $query,
                    [
                        'agent' => null,
                        'repo' => null,
                        'collection' => $filters['collection'] ?? null,
                        'since' => $filters['since'] ?? null,
                    ],
                    20,
                    actor: (string) $request->user()->id,
                    ip: ClientIp::for($request),
                    userAgent: (string) $request->userAgent(),
                ), $canonicalIds);
            } catch (\Throwable) {
                $searchError = __('Search is unavailable right now. Try again shortly.');
            }
        }

        $recent = [];
        $recentError = null;

        if ($query === '') {
            try {
                $rows = $this->artifacts->listArtifacts(
                    $team->slug,
                    $scope === 'mine' ? ['user_id' => (string) $request->user()->id] : [],
                    null,
                    10,
                )['artifacts'];

                $recent = $this->recentArtifacts($team, $rows);
            } catch (\Throwable) {
                $recentError = __('Recent artifacts are unavailable right now.');
            }
        }

        $collections = Collection::query()
            ->where('team_id', $team->id)
            ->withCount('artifacts')
            ->orderByDesc('canonical')
            ->orderBy('name')
            ->limit(6)
            ->get();

        $allCollections = Collection::query()
            ->where('team_id', $team->id)
            ->orderBy('name')
            ->get(['name', 'canonical']);

        return [
            'team' => ['slug' => $team->slug, 'name' => $team->name],
            'indexingEnabled' => $indexingEnabled,
            // Same gate as the console: with no signing secret the open route
            // can only 503 for a secure artifact, and these rows carry no tier
            // to single out the public ones that would not need it.
            'canOpenArtifacts' => ArtifactAccessLink::default()->configured(),
            'filters' => [
                'q' => $query,
                'collection' => $filters['collection'] ?? '',
                'since' => $filters['since'] ?? '',
                'scope' => $scope,
            ],
            'searched' => $searched,
            'results' => $results,
            'searchError' => $searchError,
            'recent' => $recent,
            'recentError' => $recentError,
            'collections' => $collections->map(fn (Collection $collection): array => [
                'id' => $collection->id,
                'name' => $collection->name,
                'canonical' => $collection->canonical,
                'artifactCount' => (int) $collection->artifacts_count,
            ])->all(),
            'collectionCount' => $allCollections->count(),
            'filterCollections' => $allCollections->map(fn (Collection $collection): array => [
                'name' => $collection->name,
                'canonical' => $collection->canonical,
            ])->all(),
        ];
    }

    /**
     * Map search results to the row shape the dashboard renders. The index
     * carries no tier, so every row links through the app's open route.
     *
     * @param  array<int, SearchResult>  $results
     * @param  list<string>  $canonicalIds
     * @return list<array{id: string, title: string, description: string|null, openUrl: string, snippet: string, agent: string|null, canonical: bool}>
     */
    private function searchResults(Team $team, array $results, array $canonicalIds): array
    {
        return array_map(fn (SearchResult $result): array => [
            'id' => $result->id,
            'title' => $result->title,
            'description' => $result->description,
            'openUrl' => ArtifactViewLink::forArtifact($team->slug, $result->id, null),
            'snippet' => $result->snippet,
            'agent' => $result->agent,
            'canonical' => in_array($result->id, $canonicalIds, true),
        ], $results);
    }

    /**
     * Map directory rows to recent-artifact rows, newest first. Author names
     * come from one query over the rows' user ids, restricted to this team.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{id: string, title: string, description: string|null, createdAt: string, authorName: string|null, agent: string|null, openUrl: string, revoked: bool}>
     */
    private function recentArtifacts(Team $team, array $rows): array
    {
        $rows = collect($rows)
            ->sortByDesc(fn (array $row): string => (string) ($row['created_at'] ?? ''))
            ->values()
            ->all();

        $userIds = collect($rows)->pluck('user_id')->filter()->unique()->all();

        $authorNames = $userIds === []
            ? []
            : User::query()
                ->whereIn('id', $userIds)
                ->whereHas('teamMemberships', fn ($query) => $query->where('team_id', $team->id))
                ->pluck('name', 'id')
                ->all();

        return array_map(function (array $row) use ($team, $authorNames): array {
            $provenance = is_array($row['provenance'] ?? null) ? $row['provenance'] : [];

            return [
                'id' => (string) $row['id'],
                'title' => (string) ($row['title'] ?? $row['id']),
                'description' => isset($row['description']) ? (string) $row['description'] : null,
                'createdAt' => (string) ($row['created_at'] ?? ''),
                'authorName' => $authorNames[(int) ($row['user_id'] ?? 0)] ?? null,
                'agent' => isset($provenance['agent']) ? (string) $provenance['agent'] : null,
                'openUrl' => ArtifactViewLink::forArtifact($team->slug, (string) $row['id'], null),
                'revoked' => ($row['revoked_at'] ?? null) !== null,
            ];
        }, $rows);
    }
}
