<?php

namespace App\Services\Collections;

use App\Mcp\Support\McpTimestamp;
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
     * exposed by this directory. `artifact_count` counts memberships whose
     * artifact still has an index entry for this team — deleted artifacts are
     * purged from `artifact_index_entries` and must not inflate the count
     * even if a stale membership row remains. Private artifacts are included;
     * the value is a count, never an id or title.
     *
     * @return array{collections: list<array{id: int, name: string, description: string|null, canonical: bool, artifact_count: int, created_at: string|null, updated_at: string|null}>, next_cursor: string|null}
     */
    public function list(Team $team, ?string $cursor = null, int $limit = 20): array
    {
        $position = $this->decodeCursor($cursor);

        $query = Collection::query()
            ->where('team_id', $team->id)
            ->withCount(['artifacts as artifacts_count' => function (Builder $query) use ($team): void {
                $query->whereExists(function ($sub) use ($team): void {
                    $sub->selectRaw('1')
                        ->from('artifact_index_entries')
                        ->whereColumn('artifact_index_entries.artifact_id', 'collection_artifacts.artifact_id')
                        ->where('artifact_index_entries.team_id', $team->id);
                });
            }])
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
            'created_at' => McpTimestamp::format($collection->created_at),
            'updated_at' => McpTimestamp::format($collection->updated_at),
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
     *
     * @throws InvalidCollectionCursorException
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
            throw new InvalidCollectionCursorException;
        }

        $payload = json_decode($decoded, true);
        if (! is_array($payload)
            || ! is_string($payload['name'] ?? null)
            || ! is_int($payload['id'] ?? null)
            || $payload['id'] < 1
        ) {
            throw new InvalidCollectionCursorException;
        }

        return ['name' => $payload['name'], 'id' => $payload['id']];
    }

    private function encodeCursor(string $name, int $id): string
    {
        return rtrim(strtr(base64_encode((string) json_encode(['name' => $name, 'id' => $id], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }
}
