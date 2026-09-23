<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Services\Search\SearchResult;
use App\Services\Search\SearchService;
use App\Support\ClientIp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

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

        if (! config('indexing.enabled')) {
            return response()->json([
                'errorCode' => 'search_not_configured',
                'message' => 'Search is not enabled for this workspace yet.',
                'retryable' => false,
                'nextAction' => 'enable_indexing',
            ], 503);
        }

        try {
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
                ip: ClientIp::for($request),
                userAgent: $request->userAgent() ?? 'unknown',
            );
        } catch (Throwable $exception) {
            report($exception);

            if (! config('indexing.enabled')
                || str_contains($exception->getMessage(), 'must be configured')
                || str_contains($exception->getMessage(), 'not implemented')) {
                return response()->json([
                    'errorCode' => 'search_not_configured',
                    'message' => 'Search is not enabled for this workspace yet.',
                    'retryable' => false,
                    'nextAction' => 'enable_indexing',
                ], 503);
            }

            throw $exception;
        }

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
