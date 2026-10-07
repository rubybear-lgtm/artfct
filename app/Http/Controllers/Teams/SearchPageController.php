<?php

namespace App\Http\Controllers\Teams;

use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The old member-facing search page. Search now lives on the dashboard home,
 * so this route only preserves old bookmarks by redirecting the query it can
 * still honour there.
 */
class SearchPageController extends Controller
{
    public function __invoke(Request $request, Team $team): RedirectResponse
    {
        abort_unless($request->user()->belongsToTeam($team), 404);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:500'],
            'collection' => ['nullable', 'string', 'max:255'],
            'since' => ['nullable', 'date'],
        ]);

        $parameters = ['current_team' => $team->slug];

        foreach (['q', 'collection', 'since'] as $key) {
            $value = $filters[$key] ?? null;

            if ($value !== null && $value !== '') {
                $parameters[$key] = $value;
            }
        }

        return redirect()->route('dashboard', $parameters);
    }
}
