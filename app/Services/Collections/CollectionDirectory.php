<?php

namespace App\Services\Collections;

use App\Models\Collection;
use App\Models\Team;
use Illuminate\Database\Eloquent\Builder;

final class CollectionDirectory
{
    /**
     * List safe collection metadata for one organization.
     *
     * The cursor is opaque to clients, but contains only the stable
     * `(name, id)` ordering key. No artifact IDs or creator identity are
     * exposed by this directory.
     *
     * @return array{collections: list<array{id: int, name: string, description: string|null, canonical: bool, artifact_count: int, created_at: string|null, updated_at: string|null}>, next_cursor: string|null}
     */
    public function list(Team $team, ?string $cursor = null, int $limit = 20): array
    {
        $position = $this->decodeCursor($cursor);

        $query = Collection::query()
            ->where('team_id', $team->id)
            ->withCount('artifacts')
            ->orderBy('name')
            ->orderBy('id');

        if ($position !== null) {
            $query->where(function (Builder $query) use ($position): void {
                $query->where('name', '>', $position['name'])
                    ->orWhere(function (Builder $query) use ($position): void {
                        $query->where('name', $position['name'])
                            ->where('id', '>', $position['id']);
                    });
            });
        }

        $collections = $query->limit($limit + 1)->get();
        $hasMore = $collections->count() > $limit;
        $page = $collections->take($limit);

        $items = $page->map(fn (Collection $collection): array => [
            'id' => $collection->id,
            'name' => $collection->name,
            'description' => $collection->description,
            'canonical' => $collection->canonical,
            'artifact_count' => (int) $collection->artifacts_count,
            'created_at' => $collection->created_at?->toIso8601String(),
            'updated_at' => $collection->updated_at?->toIso8601String(),
        ])->values()->all();

        $last = $page->last();

        return [
            'collections' => $items,
            'next_cursor' => $hasMore && $last instanceof Collection
                ? $this->encodeCursor($last->name, $last->id)
                : null,
        ];
    }

    /**
     * @return array{name: string, id: int}|null
     */
    private function decodeCursor(?string $cursor): ?array
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }

        $normalized = strtr($cursor, '-_', '+/');
        $normalized .= str_repeat('=', (4 - strlen($normalized) % 4) % 4);
        $decoded = base64_decode($normalized, true);
        if ($decoded === false) {
            abort(422, 'The collection cursor is invalid.');
        }

        $payload = json_decode($decoded, true);
        if (! is_array($payload)
            || ! is_string($payload['name'] ?? null)
            || ! is_int($payload['id'] ?? null)
            || $payload['id'] < 1
        ) {
            abort(422, 'The collection cursor is invalid.');
        }

        return ['name' => $payload['name'], 'id' => $payload['id']];
    }

    private function encodeCursor(string $name, int $id): string
    {
        return rtrim(strtr(base64_encode((string) json_encode(['name' => $name, 'id' => $id], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }
}
