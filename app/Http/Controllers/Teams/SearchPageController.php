<?php

namespace App\Http\Controllers\Teams;

use App\Http\Controllers\Controller;
use App\Models\Collection;
use App\Models\Team;
use App\Services\Search\SearchResult;
use App\Services\Search\SearchService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The member-facing search page: the same `SearchService` the token API
 * uses, behind the session, scoped to a team the user belongs to.
 */
class SearchPageController extends Controller
{
    public function __invoke(Request $request, Team $team, SearchService $search): Response
    {
        abort_unless($request->user()->belongsToTeam($team), 404);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:500'],
            'agent' => ['nullable', 'string', 'max:100'],
            'repo' => ['nullable', 'string', 'max:255'],
            'collection' => ['nullable', 'string', 'max:255'],
        ]);

        $indexingEnabled = (bool) config('indexing.enabled');
        $query = trim((string) ($filters['q'] ?? ''));
        $results = [];
        $error = null;

        if ($indexingEnabled && $query !== '') {
            try {
                $results = array_map(fn (SearchResult $result): array => [
                    'id' => $result->id,
                    'title' => $result->title,
                    'description' => $result->description,
                    'url' => $result->url,
                    'snippet' => $result->snippet,
                    'agent' => $result->agent,
                    'repoUrl' => $result->repoUrl,
                ], $search->search(
                    $team,
                    $query,
                    [
                        'agent' => $filters['agent'] ?? null,
                        'repo' => $filters['repo'] ?? null,
                        'collection' => $filters['collection'] ?? null,
                    ],
                    20,
                    actor: (string) $request->user()->id,
                    ip: (string) $request->ip(),
                    userAgent: (string) $request->userAgent(),
                ));
            } catch (\Throwable) {
                $error = __('Search is unavailable right now. Try again shortly.');
            }
        }

        return Inertia::render('teams/search', [
            'team' => ['slug' => $team->slug, 'name' => $team->name],
            'indexingEnabled' => $indexingEnabled,
            'filters' => [
                'q' => $query,
                'agent' => $filters['agent'] ?? '',
                'repo' => $filters['repo'] ?? '',
                'collection' => $filters['collection'] ?? '',
            ],
            'collections' => Collection::query()->where('team_id', $team->id)->orderBy('name')->get(['name', 'canonical']),
            'results' => $results,
            'searched' => $indexingEnabled && $query !== '',
            'error' => $error,
        ]);
    }
}
