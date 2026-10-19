<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Collection;
use App\Models\Team;
use App\Models\User;
use App\Services\Collections\ArtifactExistence;
use App\Services\Collections\CollectionDirectory;
use App\Services\Collections\CollectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class CollectionController extends Controller
{
    public function index(Request $request, CollectionDirectory $directory): JsonResponse
    {
        $validated = $request->validate([
            'cursor' => ['nullable', 'string', 'max:512'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        /** @var Team $team */
        $team = $request->attributes->get('org_jwt_team');

        return response()->json($directory->list(
            $team,
            $validated['cursor'] ?? null,
            (int) ($validated['limit'] ?? 20),
        ));
    }

    public function store(Request $request, CollectionService $collections): JsonResponse
    {
        $this->requireScope($request, 'collections:write');
        /** @var Team $team */
        $team = $request->attributes->get('org_jwt_team');
        /** @var array{user_id: string} $claims */
        $claims = $request->attributes->get('org_jwt_claims');
        $user = User::query()->findOrFail((int) $claims['user_id']);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('collections', 'name')->where('team_id', $team->id)],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $collection = $collections->create($team, $user, $validated['name'], $validated['description'] ?? null);

        return response()->json([
            'id' => $collection->id,
            'name' => $collection->name,
            'description' => $collection->description,
            'canonical' => $collection->canonical,
            'artifact_count' => 0,
            'created_at' => $collection->created_at?->toIso8601String(),
            'updated_at' => $collection->updated_at?->toIso8601String(),
        ], 201);
    }

    public function addArtifact(Request $request, int $collection, CollectionService $collections): JsonResponse
    {
        $this->requireScope($request, 'collections:write');
        /** @var Team $team */
        $team = $request->attributes->get('org_jwt_team');
        $model = Collection::query()->where('team_id', $team->id)->findOrFail($collection);
        $validated = $request->validate([
            'artifact_id' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9]+$/'],
        ]);

        $existence = app(ArtifactExistence::class)->check($request->bearerToken(), $validated['artifact_id']);

        if ($existence === ArtifactExistence::MISSING) {
            return response()->json(['error' => 'artifact_not_found', 'message' => 'That artifact was not found in the authenticated workspace.'], 404);
        }

        if ($existence === ArtifactExistence::UNAVAILABLE) {
            return response()->json(['error' => 'upstream_unavailable', 'message' => 'The artifact service is temporarily unavailable.'], 503);
        }

        $collections->addArtifact($model, $validated['artifact_id']);

        return response()->json([
            'collection_id' => $model->id,
            'artifact_id' => $validated['artifact_id'],
        ]);
    }

    private function requireScope(Request $request, string $scope): void
    {
        /** @var array{scope?: string} $claims */
        $claims = $request->attributes->get('org_jwt_claims');
        $scopes = preg_split('/\s+/', trim((string) ($claims['scope'] ?? '')));

        abort_unless(in_array($scope, $scopes ?: [], true), 403, "The connection requires the {$scope} scope.");
    }
}
