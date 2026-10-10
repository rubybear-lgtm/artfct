<?php

namespace App\Mcp\Tools;

use App\Enums\UsageEventType;
use App\Mcp\Support\McpArtifactLink;
use App\Mcp\Support\McpContext;
use App\Mcp\Support\McpErrorResponse;
use App\Mcp\Support\McpTelemetry;
use App\Models\ArtifactUsageEvent;
use App\Models\Collection;
use App\Models\Team;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Http;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('List the team\'s artifacts by who made them, when, how they are shared, or how often they were opened. Use this to answer questions like "what did I publish this week" or "our most-opened dashboards". To find artifacts about a topic, use search_artifacts instead.')]
#[Name('list_artifacts')]
#[IsReadOnly]
#[IsIdempotent]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
final class ListArtifactsTool extends Tool
{
    /** The Worker's per-page ceiling for the internal most-viewed scan. */
    private const RANKING_PAGE_SIZE = 200;

    /** The most artifacts the internal most-viewed scan will rank. */
    private const RANKING_MAX_PAGES = 5;

    /** The Worker refuses a longer `ids` list, so a collection is capped here. */
    private const MAX_IDS = 200;

    /** @var array<string, mixed> */
    protected ?array $meta = [
        'artfct' => [
            'contractVersion' => '1.0.0',
            'toolVersion' => '1.0.0',
            'owner' => 'artfct-mcp',
            'requiredScopes' => ['artifacts:read'],
            'compatibility' => 'stable',
            'examples' => [[
                'description' => 'List the artifacts published this week.',
                'arguments' => ['owner' => 'me', 'created_after' => '2026-10-01T00:00:00Z'],
            ]],
        ],
    ];

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $startedAt = hrtime(true);
        McpContext::requireScope('artifacts:read', 'list_artifacts');

        $validated = $request->validate([
            'owner' => ['nullable', 'string', 'max:255'],
            'sharing' => ['nullable', 'string', 'in:private,team,public'],
            'collection' => ['nullable', 'string', 'max:255'],
            'agent' => ['nullable', 'string', 'max:100'],
            'created_after' => ['nullable', 'date'],
            'created_before' => ['nullable', 'date'],
            'updated_after' => ['nullable', 'date'],
            'updated_before' => ['nullable', 'date'],
            'title_contains' => ['nullable', 'string', 'max:200'],
            'kind' => ['nullable', 'string', 'in:html,markdown,table'],
            'sort' => ['nullable', 'string', 'in:updated,created,most_viewed,title'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            'cursor' => ['nullable', 'string', 'max:4096'],
        ]);

        $team = McpContext::team();
        $limit = (int) ($validated['limit'] ?? 20);
        $sort = is_string($validated['sort'] ?? null) ? $validated['sort'] : 'updated';

        $workerBaseUrl = config('services.worker.base_url');
        if (! is_string($workerBaseUrl) || $workerBaseUrl === '') {
            app(McpTelemetry::class)->record('list_artifacts', 'error', $startedAt);

            return McpErrorResponse::error('The artifact service is not configured.', 'configuration_error');
        }

        /** @var list<string> $notes */
        $notes = [];

        $owner = $validated['owner'] ?? null;
        $ownerUserId = null;
        if ($owner === 'me') {
            $ownerUserId = McpContext::actor();
        } elseif (is_string($owner) && $owner !== '' && $owner !== 'anyone') {
            $ownerUserId = $this->memberIdByEmail($team, $owner);
            if ($ownerUserId === null) {
                // An email that is not a team member matches nothing, which is
                // an empty list rather than an error.
                app(McpTelemetry::class)->record('list_artifacts', 'success', $startedAt);

                return Response::structured(['artifacts' => [], 'next_cursor' => null]);
            }
        }

        $baseParams = ['live' => 'true'];
        if ($ownerUserId !== null) {
            $baseParams['owner_user_id'] = $ownerUserId;
        }
        foreach (['sharing', 'agent', 'created_after', 'created_before', 'updated_after', 'updated_before', 'title_contains', 'kind'] as $filter) {
            if (isset($validated[$filter]) && $validated[$filter] !== '') {
                $baseParams[$filter] = $validated[$filter];
            }
        }

        $collection = $validated['collection'] ?? null;
        if (is_string($collection) && $collection !== '') {
            $ids = $this->collectionArtifactIds($team, $collection);
            if ($ids === null) {
                app(McpTelemetry::class)->record('list_artifacts', 'collection_not_found', $startedAt);

                return McpErrorResponse::error('That collection was not found in the authenticated workspace.', 'collection_not_found');
            }

            if ($ids === []) {
                app(McpTelemetry::class)->record('list_artifacts', 'success', $startedAt);

                return Response::structured(['artifacts' => [], 'next_cursor' => null]);
            }

            if (count($ids) > self::MAX_IDS) {
                $ids = array_slice($ids, 0, self::MAX_IDS);
                $notes[] = 'That collection has more than '.self::MAX_IDS.' artifacts, so only the '.self::MAX_IDS.' most recently added are listed.';
            }

            $baseParams['ids'] = implode(',', $ids);
        }

        $url = rtrim($workerBaseUrl, '/')."/v1/orgs/{$team->slug}/artifacts";
        $incoming = $this->decodeCursor($validated['cursor'] ?? null);

        if ($sort === 'most_viewed') {
            $result = $this->listMostViewed($url, $team, $baseParams, $incoming, $limit, $notes, $startedAt);
        } else {
            $result = $this->listSorted($url, $team, $baseParams, $sort, $incoming, $limit, $startedAt);
        }

        if ($result instanceof Response) {
            return $result;
        }

        app(McpTelemetry::class)->record('list_artifacts', 'success', $startedAt);

        return Response::structured($notes === []
            ? $result
            : $result + ['note' => implode(' ', $notes)]);
    }

    /**
     * The straight-through sorts: the Worker's own ordering and cursor are
     * handed back wrapped so the next call can pass the cursor on unchanged.
     *
     * @param  array<string, mixed>  $baseParams
     * @param  array<string, mixed>|null  $incoming
     * @return array{artifacts: list<array<string, mixed>>, next_cursor: string|null}|Response
     */
    private function listSorted(
        string $url,
        Team $team,
        array $baseParams,
        string $sort,
        ?array $incoming,
        int $limit,
        int $startedAt,
    ): array|Response {
        $workerSort = match ($sort) {
            'created' => 'created_desc',
            'title' => 'title_asc',
            default => 'updated_desc',
        };

        $params = $baseParams + ['sort' => $workerSort, 'limit' => $limit];

        $workerCursor = is_array($incoming) && ($incoming['s'] ?? null) === $workerSort && is_string($incoming['c'] ?? null)
            ? $incoming['c']
            : null;
        if ($workerCursor !== null) {
            $params['cursor'] = $workerCursor;
        }

        try {
            $response = Http::withToken(McpContext::httpRequest()->bearerToken())->get($url, $params);
        } catch (\Throwable $exception) {
            report($exception);
            app(McpTelemetry::class)->record('list_artifacts', 'error', $startedAt);

            return McpErrorResponse::error('The artifact service is temporarily unavailable.', 'upstream_unavailable', true);
        }

        if ($failure = $this->failure($response, $startedAt)) {
            return $failure;
        }

        $body = $response->json();
        $body = is_array($body) ? $body : [];
        $artifacts = is_array($body['artifacts'] ?? null)
            ? array_values(array_filter($body['artifacts'], 'is_array'))
            : [];

        $rows = $this->withViewCounts($team, $artifacts);
        $next = $body['next_cursor'] ?? null;

        return [
            'artifacts' => $this->outputItems($team, $rows),
            'next_cursor' => is_string($next) && $next !== ''
                ? $this->encodeCursor(['s' => $workerSort, 'c' => $next])
                : null,
        ];
    }

    /**
     * The ranking sort: the Worker cannot order by view count, so every visible
     * matching artifact is fetched (newest first, bounded) and ranked here.
     *
     * @param  array<string, mixed>  $baseParams
     * @param  array<string, mixed>|null  $incoming
     * @param  list<string>  $notes
     * @return array{artifacts: list<array<string, mixed>>, next_cursor: string|null}|Response
     */
    private function listMostViewed(
        string $url,
        Team $team,
        array $baseParams,
        ?array $incoming,
        int $limit,
        array &$notes,
        int $startedAt,
    ): array|Response {
        $fetched = [];
        $cursor = null;
        $truncated = false;

        for ($page = 0; $page < self::RANKING_MAX_PAGES; $page++) {
            $params = $baseParams + ['sort' => 'created_desc', 'limit' => self::RANKING_PAGE_SIZE];
            if ($cursor !== null) {
                $params['cursor'] = $cursor;
            }

            try {
                $response = Http::withToken(McpContext::httpRequest()->bearerToken())->get($url, $params);
            } catch (\Throwable $exception) {
                report($exception);
                app(McpTelemetry::class)->record('list_artifacts', 'error', $startedAt);

                return McpErrorResponse::error('The artifact service is temporarily unavailable.', 'upstream_unavailable', true);
            }

            if ($failure = $this->failure($response, $startedAt)) {
                return $failure;
            }

            $body = $response->json();
            $body = is_array($body) ? $body : [];
            $artifacts = is_array($body['artifacts'] ?? null) ? $body['artifacts'] : [];
            foreach ($artifacts as $artifact) {
                if (is_array($artifact)) {
                    $fetched[] = $artifact;
                }
            }

            $cursor = is_string($body['next_cursor'] ?? null) && $body['next_cursor'] !== ''
                ? $body['next_cursor']
                : null;

            if ($cursor === null) {
                break;
            }

            if ($page === self::RANKING_MAX_PAGES - 1) {
                $truncated = true;
            }
        }

        if ($truncated) {
            $notes[] = 'This workspace has more than '.(self::RANKING_PAGE_SIZE * self::RANKING_MAX_PAGES).' artifacts, so only the newest were ranked by view count.';
        }

        $ranked = $this->rankByViews($team, $fetched);

        $position = is_array($incoming)
            && ($incoming['s'] ?? null) === 'most_viewed'
            && is_int($incoming['v'] ?? null)
            && is_string($incoming['u'] ?? null)
            && is_string($incoming['id'] ?? null)
                ? $incoming
                : null;

        if ($position !== null) {
            $ranked = array_values(array_filter(
                $ranked,
                fn (array $row): bool => $this->isAfterMostViewedPosition($row, $position),
            ));
        }

        $page = array_slice($ranked, 0, $limit);
        $last = $page === [] ? null : $page[array_key_last($page)];

        return [
            'artifacts' => $this->outputItems($team, $page),
            'next_cursor' => count($ranked) > $limit && $last !== null
                ? $this->encodeCursor([
                    's' => 'most_viewed',
                    'v' => $last['view_count'],
                    'u' => $last['updated_at'],
                    'id' => (string) $last['artifact']['id'],
                ])
                : null,
        ];
    }

    /**
     * Rank the fetched artifacts by (view count desc, updated_at desc, id asc).
     *
     * @param  list<array<string, mixed>>  $artifacts
     * @return list<array{artifact: array<string, mixed>, view_count: int, updated_at: string}>
     */
    private function rankByViews(Team $team, array $artifacts): array
    {
        $counts = $this->viewCounts($team, array_map(
            fn (array $artifact): mixed => $artifact['id'] ?? null,
            $artifacts,
        ));

        $rows = [];
        foreach ($artifacts as $artifact) {
            $id = $artifact['id'] ?? null;
            if (! is_string($id)) {
                continue;
            }

            $rows[] = [
                'artifact' => $artifact,
                'view_count' => (int) ($counts[$id] ?? 0),
                'updated_at' => is_string($artifact['updated_at'] ?? null) ? $artifact['updated_at'] : '',
            ];
        }

        usort($rows, function (array $a, array $b): int {
            if ($a['view_count'] !== $b['view_count']) {
                return $b['view_count'] <=> $a['view_count'];
            }

            $byUpdated = strcmp($b['updated_at'], $a['updated_at']);
            if ($byUpdated !== 0) {
                return $byUpdated;
            }

            return strcmp((string) $a['artifact']['id'], (string) $b['artifact']['id']);
        });

        return $rows;
    }

    /**
     * Whether a ranked row falls strictly after the keyset cursor position.
     *
     * @param  array{artifact: array<string, mixed>, view_count: int, updated_at: string}  $row
     * @param  array<string, mixed>  $position
     */
    private function isAfterMostViewedPosition(array $row, array $position): bool
    {
        if ($row['view_count'] !== $position['v']) {
            return $row['view_count'] < $position['v'];
        }

        if ($row['updated_at'] !== $position['u']) {
            return strcmp($row['updated_at'], (string) $position['u']) < 0;
        }

        return strcmp((string) $row['artifact']['id'], (string) $position['id']) > 0;
    }

    /**
     * Attach the caller's view count to each artifact, from one grouped query.
     *
     * @param  list<array<string, mixed>>  $artifacts
     * @return list<array{artifact: array<string, mixed>, view_count: int, updated_at: string}>
     */
    private function withViewCounts(Team $team, array $artifacts): array
    {
        $counts = $this->viewCounts($team, array_map(
            fn (array $artifact): mixed => $artifact['id'] ?? null,
            $artifacts,
        ));

        $rows = [];
        foreach ($artifacts as $artifact) {
            $id = $artifact['id'] ?? null;
            if (! is_string($id)) {
                continue;
            }

            $rows[] = [
                'artifact' => $artifact,
                'view_count' => (int) ($counts[$id] ?? 0),
                'updated_at' => is_string($artifact['updated_at'] ?? null) ? $artifact['updated_at'] : '',
            ];
        }

        return $rows;
    }

    /**
     * @param  list<mixed>  $ids
     * @return array<string, int>
     */
    private function viewCounts(Team $team, array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, 'is_string')));
        if ($ids === []) {
            return [];
        }

        return ArtifactUsageEvent::query()
            ->where('team_id', $team->id)
            ->where('event_type', UsageEventType::Viewed)
            ->whereIn('artifact_id', $ids)
            ->groupBy('artifact_id')
            ->selectRaw('artifact_id, count(*) as aggregate')
            ->pluck('aggregate', 'artifact_id')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
    }

    /**
     * Turn ranked rows into the tool's item shape.
     *
     * @param  list<array{artifact: array<string, mixed>, view_count: int, updated_at: string}>  $rows
     * @return list<array<string, mixed>>
     */
    private function outputItems(Team $team, array $rows): array
    {
        $ownerNames = $this->ownerNames($team, $rows);
        $actor = McpContext::actor();

        $items = [];
        foreach ($rows as $row) {
            $artifact = $row['artifact'];
            $id = (string) ($artifact['id'] ?? '');
            $ownerUserId = $artifact['owner_user_id'] ?? null;

            $owner = null;
            if (is_string($ownerUserId) && $ownerUserId !== '') {
                if ($ownerUserId === $actor) {
                    $owner = 'you';
                } elseif (isset($ownerNames[$ownerUserId])) {
                    $owner = $ownerNames[$ownerUserId];
                }
            }

            $link = McpArtifactLink::forArtifact(
                $team->slug,
                $id,
                $this->tier($artifact['sharing'] ?? null),
            );

            $item = [
                'id' => $artifact['id'] ?? null,
                'title' => $artifact['title'] ?? null,
                'description' => $artifact['description'] ?? null,
                'sharing' => $artifact['sharing'] ?? null,
                'owner' => $owner,
                'agent' => data_get($artifact, 'provenance.agent'),
                'version' => $artifact['version'] ?? null,
                'kind' => $artifact['kind'] ?? null,
                'created_at' => $artifact['created_at'] ?? null,
                'updated_at' => $artifact['updated_at'] ?? null,
                'view_count' => $row['view_count'],
                'can_edit' => (bool) ($artifact['can_edit'] ?? false),
            ];

            if ($link instanceof Response) {
                $item['view_url'] = null;
                $item['link_error'] = McpArtifactLink::ERROR_CODE;
            } else {
                $item['view_url'] = $link;
            }

            $items[] = $item;
        }

        return $items;
    }

    /**
     * @param  list<array{artifact: array<string, mixed>, view_count: int, updated_at: string}>  $rows
     * @return array<string, string>
     */
    private function ownerNames(Team $team, array $rows): array
    {
        $ownerIds = [];
        foreach ($rows as $row) {
            $id = $row['artifact']['owner_user_id'] ?? null;
            if (is_string($id) && $id !== '') {
                $ownerIds[$id] = true;
            }
        }

        if ($ownerIds === []) {
            return [];
        }

        return $team->members()
            ->whereIn('users.id', array_keys($ownerIds))
            ->pluck('name', 'users.id')
            ->all();
    }

    /**
     * The collection's artifact ids, most recently added first, or null when no
     * collection in the team matches.
     *
     * @return list<string>|null
     */
    private function collectionArtifactIds(Team $team, string $identifier): ?array
    {
        $collection = Collection::query()
            ->where('team_id', $team->id)
            ->where(function ($query) use ($identifier): void {
                $query->where('name', $identifier);
                if (ctype_digit($identifier)) {
                    $query->orWhere('id', (int) $identifier);
                }
            })
            ->first();

        if (! $collection instanceof Collection) {
            return null;
        }

        return $collection->artifacts()
            ->orderByDesc('added_at')
            ->orderByDesc('id')
            ->pluck('artifact_id')
            ->filter(fn (mixed $id): bool => is_string($id))
            ->values()
            ->all();
    }

    /**
     * The caller's team-member id for an email, or null when they are not a
     * member.
     */
    private function memberIdByEmail(Team $team, string $email): ?string
    {
        $id = $team->members()
            ->whereRaw('lower(users.email) = ?', [mb_strtolower($email)])
            ->value('users.id');

        return is_int($id) || is_string($id) ? (string) $id : null;
    }

    /**
     * Map the Worker's sharing level to the link tier.
     */
    private function tier(mixed $sharing): ?string
    {
        return match ($sharing) {
            'team' => 'secure',
            'public' => 'public',
            'private' => 'private',
            default => null,
        };
    }

    /**
     * Map a failed Worker response, or null when the response succeeded.
     */
    private function failure(HttpResponse $response, int $startedAt): ?Response
    {
        if ($response->successful()) {
            return null;
        }

        if ($response->status() === 401) {
            app(McpTelemetry::class)->record('list_artifacts', 'unauthorized', $startedAt);

            return McpErrorResponse::error('The artifact service rejected this connection.', 'unauthorized');
        }

        if ($response->status() === 403) {
            app(McpTelemetry::class)->record('list_artifacts', 'forbidden', $startedAt);

            return McpErrorResponse::error('This connection may not list that workspace.', 'forbidden');
        }

        if ($response->status() === 422) {
            app(McpTelemetry::class)->record('list_artifacts', 'invalid_request', $startedAt);

            return McpErrorResponse::error('The artifact service rejected the list filters.', 'invalid_request');
        }

        app(McpTelemetry::class)->record('list_artifacts', 'error', $startedAt);

        return McpErrorResponse::error('The artifact service is temporarily unavailable.', 'upstream_unavailable', true);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function encodeCursor(array $payload): string
    {
        return rtrim(strtr(
            base64_encode((string) json_encode($payload, JSON_THROW_ON_ERROR)),
            '+/',
            '-_',
        ), '=');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeCursor(?string $cursor): ?array
    {
        if (! is_string($cursor) || $cursor === '') {
            return null;
        }

        $normalized = strtr($cursor, '-_', '+/');
        $normalized .= str_repeat('=', (4 - strlen($normalized) % 4) % 4);

        $decoded = base64_decode($normalized, true);
        if ($decoded === false) {
            return null;
        }

        $payload = json_decode($decoded, true);

        return is_array($payload) ? $payload : null;
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'owner' => $schema->string()->max(255)->description('Filter by owner: `me`, `anyone` or a team member\'s email address.')->nullable(),
            'sharing' => $schema->string()->enum(['private', 'team', 'public'])->description('Only artifacts with this sharing level.')->nullable(),
            'collection' => $schema->string()->max(255)->description('Only artifacts in this collection, named by id or name.')->nullable(),
            'agent' => $schema->string()->max(100)->description('Only artifacts published by this agent identity.')->nullable(),
            'created_after' => $schema->string()->description('Only artifacts created at or after this ISO 8601 time.')->nullable(),
            'created_before' => $schema->string()->description('Only artifacts created at or before this ISO 8601 time.')->nullable(),
            'updated_after' => $schema->string()->description('Only artifacts updated at or after this ISO 8601 time.')->nullable(),
            'updated_before' => $schema->string()->description('Only artifacts updated at or before this ISO 8601 time.')->nullable(),
            'title_contains' => $schema->string()->max(200)->description('Only artifacts whose title contains this text, case-insensitively.')->nullable(),
            'kind' => $schema->string()->enum(['html', 'markdown', 'table'])->description('Only artifacts whose entrypoint is this file type.')->nullable(),
            'sort' => $schema->string()->enum(['updated', 'created', 'most_viewed', 'title'])->description('Order by when most recently updated (default), when created, how often opened, or title.')->nullable(),
            'limit' => $schema->integer()->min(1)->max(50)->description('Maximum artifacts to return, from 1 to 50. Defaults to 20.')->nullable(),
            'cursor' => $schema->string()->max(4096)->description('Opaque cursor returned by a previous page.')->nullable(),
        ];
    }
}
