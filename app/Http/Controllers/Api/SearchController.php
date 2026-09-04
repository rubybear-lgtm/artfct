<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Services\Search\SearchResult;
use App\Services\Search\SearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function search(Request $request, SearchService $search): JsonResponse
    {
        $validated = $request->validate([
            'query' => ['required', 'string', 'min:1'],
            'repo' => ['nullable', 'string'],
            'agent' => ['nullable', 'string'],
            'since' => ['nullable', 'date'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            'collection' => ['nullable', 'string'],
        ]);

        /** @var Team $team */
        $team = $request->attributes->get('org_jwt_team');
        $claims = $request->attributes->get('org_jwt_claims');

        $results = $search->search(
            $team,
            $validated['query'],
            [
                'repo' => $validated['repo'] ?? null,
                'agent' => $validated['agent'] ?? null,
                'since' => $validated['since'] ?? null,
                'collection' => $validated['collection'] ?? null,
            ],
            (int) ($validated['limit'] ?? 10),
            actor: (string) ($claims['user_id'] ?? 'unknown'),
            ip: $request->ip() ?? 'unknown',
            userAgent: $request->userAgent() ?? 'unknown',
        );

        return response()->json([
            'results' => array_map(fn (SearchResult $result): array => [
                'id' => $result->id,
                'title' => $result->title,
                'description' => $result->description,
                'url' => $result->url,
                'snippet' => $result->snippet,
                'provenance' => [
                    'agent' => $result->agent,
                    'repo_url' => $result->repoUrl,
                    'commit_sha' => $result->commitSha,
                ],
                'score' => $result->score,
            ], $results),
        ]);
    }
}
