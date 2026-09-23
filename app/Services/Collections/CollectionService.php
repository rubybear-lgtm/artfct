<?php

namespace App\Services\Collections;

use App\Models\Collection;
use App\Models\CollectionArtifact;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * spec 16: collections "kept deliberately thin" — any member can create
 * one and add artifacts to it; only pinning one canonical is gated.
 */
final class CollectionService
{
    public function create(Team $team, User $creator, string $name, ?string $description = null): Collection
    {
        return Collection::query()->create([
            'team_id' => $team->id,
            'name' => $name,
            'description' => $description,
            'created_by_user_id' => $creator->id,
        ]);
    }

    public function addArtifact(Collection $collection, string $artifactId): void
    {
        CollectionArtifact::query()->firstOrCreate(
            ['collection_id' => $collection->id, 'artifact_id' => $artifactId],
            ['added_at' => Carbon::now()],
        );
    }

    public function removeArtifact(Collection $collection, string $artifactId): void
    {
        CollectionArtifact::query()
            ->where('collection_id', $collection->id)
            ->where('artifact_id', $artifactId)
            ->delete();
    }

    /**
     * @throws AuthorizationException when `$user`
     *                                lacks the
     *                                `pinCanonicalCollection`
     *                                permission.
     */
    public function pinCanonical(Collection $collection, User $user): void
    {
        Gate::forUser($user)->authorize('pinCanonicalCollection', $collection->team);

        $collection->update(['canonical' => true]);
    }

    public function unpinCanonical(Collection $collection, User $user): void
    {
        Gate::forUser($user)->authorize('pinCanonicalCollection', $collection->team);

        $collection->update(['canonical' => false]);
    }
}
