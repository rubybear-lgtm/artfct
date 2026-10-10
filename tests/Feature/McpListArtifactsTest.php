<?php

use App\Enums\TeamRole;
use App\Enums\UsageEventType;
use App\Models\ArtifactUsageEvent;
use App\Models\Collection;
use App\Models\CollectionArtifact;
use App\Models\Team;
use App\Services\Auth\OrgJwtService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/*
 * RUB-440: `list_artifacts` is the browse counterpart to `search_artifacts`.
 * It filters, sorts and pages over the Worker's org list endpoint, called with
 * the MCP caller's own bearer token so the Worker keeps enforcing the Private
 * rule. These tests drive the hosted tool and assert both the Worker request
 * it emits and the structured page it returns.
 */

/** The query string the tool sent to the Worker. */
function listArtifactsWorkerQuery(Request $request): array
{
    parse_str((string) parse_url((string) $request->url(), PHP_URL_QUERY), $query);

    return $query;
}

/** Call `list_artifacts` through the hosted MCP server. */
function callListArtifacts(string $token, array $arguments = []): TestResponse
{
    return test()->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => ['name' => 'list_artifacts', 'arguments' => $arguments],
    ]);
}

/**
 * One Worker list item in the shape the org list endpoint returns.
 *
 * @return array<string, mixed>
 */
function listArtifactsWorkerItem(array $overrides = []): array
{
    return array_merge([
        'id' => 'abc123def4567',
        'title' => 'Weekly dashboard',
        'description' => 'A dashboard',
        'created_at' => '2026-10-01T00:00:00Z',
        'updated_at' => '2026-10-02T00:00:00Z',
        'version' => 1,
        'kind' => 'html',
        'sharing' => 'team',
        'edit_access' => 'view',
        'owner_user_id' => 'user_1',
        'can_edit' => false,
        'can_change_sharing' => false,
        'provenance' => ['agent' => 'claude-code', 'repo_url' => null, 'commit_sha' => null],
        'revoked_at' => null,
    ], $overrides);
}

/** Record `$count` view events for one artifact. */
function listArtifactsRecordViews(Team $team, string $artifactId, int $count): void
{
    $rows = [];
    for ($i = 0; $i < $count; $i++) {
        $rows[] = [
            'team_id' => $team->id,
            'artifact_id' => $artifactId,
            'event_type' => UsageEventType::Viewed->value,
            'actor_user_id' => null,
            'viewer_key' => null,
            'related_artifact_id' => null,
            'occurred_at' => now(),
        ];
    }

    if ($rows !== []) {
        ArtifactUsageEvent::insert($rows);
    }
}

/** The base64url keyset cursor `list_artifacts` returns. */
function listArtifactsDecodeCursor(string $cursor): array
{
    $normalized = strtr($cursor, '-_', '+/');
    $normalized .= str_repeat('=', (4 - strlen($normalized) % 4) % 4);

    return json_decode((string) base64_decode($normalized, true), true, flags: JSON_THROW_ON_ERROR);
}

/** The base64url cursor a caller would send, built by hand. */
function listArtifactsEncodeCursor(array $payload): string
{
    return rtrim(strtr(base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
}

test('list_artifacts sends every filter to the Worker with live true', function () {
    $team = Team::factory()->create(['slug' => 'rub-440-filters']);
    $token = remoteMcpToken($team);
    $actor = OrgJwtService::default()->verify($token)['user_id'];
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake(['worker.test/v1/orgs/*' => Http::response(['artifacts' => [], 'next_cursor' => null])]);

    callListArtifacts($token, [
        'owner' => 'me',
        'sharing' => 'team',
        'agent' => 'claude-code',
        'created_after' => '2026-10-01T00:00:00Z',
        'updated_before' => '2026-10-31T00:00:00Z',
        'title_contains' => 'dashboard',
        'kind' => 'html',
        'limit' => 15,
    ])->assertOk()->assertJsonPath('result.isError', false);

    Http::assertSent(function (Request $request) use ($actor): bool {
        $query = listArtifactsWorkerQuery($request);

        return str_ends_with((string) parse_url((string) $request->url(), PHP_URL_PATH), '/v1/orgs/rub-440-filters/artifacts')
            && $query['live'] === 'true'
            && $query['owner_user_id'] === $actor
            && $query['sharing'] === 'team'
            && $query['agent'] === 'claude-code'
            && $query['created_after'] === '2026-10-01T00:00:00Z'
            && $query['updated_before'] === '2026-10-31T00:00:00Z'
            && $query['title_contains'] === 'dashboard'
            && $query['kind'] === 'html'
            && $query['limit'] === '15'
            && $query['sort'] === 'updated_desc'
            && ! isset($query['cursor']);
    });
});

test('list_artifacts maps owner anyone to no owner filter and an email to that member id', function () {
    $team = Team::factory()->create(['slug' => 'rub-440-owner']);
    $token = remoteMcpToken($team);
    $member = memberOfTeam($team, TeamRole::Member);
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake(['worker.test/v1/orgs/*' => Http::response(['artifacts' => [], 'next_cursor' => null])]);

    callListArtifacts($token, ['owner' => 'anyone'])->assertOk();
    callListArtifacts($token, ['owner' => $member->email])->assertOk();

    Http::assertSent(fn (Request $request): bool => ($query = listArtifactsWorkerQuery($request)) !== []
        && ! isset($query['owner_user_id']));

    Http::assertSent(fn (Request $request): bool => (listArtifactsWorkerQuery($request)['owner_user_id'] ?? null) === (string) $member->id);
});

test('list_artifacts returns an empty list for an owner email that is not a team member', function () {
    $team = Team::factory()->create(['slug' => 'rub-440-owner-unknown']);
    $token = remoteMcpToken($team);
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake();

    $response = callListArtifacts($token, ['owner' => 'ghost@example.com'])
        ->assertOk()
        ->assertJsonPath('result.isError', false);

    expect($response->json('result.structuredContent.artifacts'))->toBe([])
        ->and($response->json('result.structuredContent.next_cursor'))->toBeNull();

    Http::assertNothingSent();
});

test('list_artifacts resolves a collection by id and by name into an ids filter', function () {
    $team = Team::factory()->create(['slug' => 'rub-440-collection']);
    $token = remoteMcpToken($team);
    $collection = Collection::factory()->for($team)->create(['name' => 'Launch dashboards']);
    CollectionArtifact::create(['collection_id' => $collection->id, 'artifact_id' => 'abc123def4567', 'added_at' => now()]);
    CollectionArtifact::create(['collection_id' => $collection->id, 'artifact_id' => 'abc123def4568', 'added_at' => now()->addMinute()]);
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake(['worker.test/v1/orgs/*' => Http::response(['artifacts' => [], 'next_cursor' => null])]);

    callListArtifacts($token, ['collection' => (string) $collection->id])->assertOk();
    callListArtifacts($token, ['collection' => 'Launch dashboards'])->assertOk();

    // Most recently added first, so the newer member leads.
    Http::assertSent(fn (Request $request): bool => (listArtifactsWorkerQuery($request)['ids'] ?? null) === 'abc123def4568,abc123def4567');

    expect(Http::recorded(fn ($request): bool => isset(listArtifactsWorkerQuery($request)['ids'])))->toHaveCount(2);
});

test('list_artifacts reports an unknown collection without calling the Worker', function () {
    $team = Team::factory()->create(['slug' => 'rub-440-collection-missing']);
    $token = remoteMcpToken($team);
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake();

    callListArtifacts($token, ['collection' => 'nope'])
        ->assertOk()
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0._meta.artfct.errorCode', 'collection_not_found');

    Http::assertNothingSent();
});

test('list_artifacts caps a collection at the 200 most recently added artifacts and says so', function () {
    $team = Team::factory()->create(['slug' => 'rub-440-collection-cap']);
    $token = remoteMcpToken($team);
    $collection = Collection::factory()->for($team)->create();

    $rows = [];
    for ($i = 0; $i < 201; $i++) {
        $rows[] = [
            'collection_id' => $collection->id,
            'artifact_id' => sprintf('artifact-%04d', $i),
            'added_at' => now()->addSeconds($i)->toDateTimeString(),
        ];
    }
    CollectionArtifact::insert($rows);

    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake(['worker.test/v1/orgs/*' => Http::response(['artifacts' => [], 'next_cursor' => null])]);

    $response = callListArtifacts($token, ['collection' => (string) $collection->id])->assertOk();

    Http::assertSent(function (Request $request): bool {
        $ids = explode(',', listArtifactsWorkerQuery($request)['ids'] ?? '');

        // The newest artifact is in and the oldest is cut.
        return count($ids) === 200
            && $ids[0] === 'artifact-0200'
            && ! in_array('artifact-0000', $ids, true);
    });

    expect($response->json('result.structuredContent.note'))->toContain('more than 200');
});

test('list_artifacts maps each requested sort to the Worker sort', function () {
    $team = Team::factory()->create(['slug' => 'rub-440-sorts']);
    $token = remoteMcpToken($team);
    config(['services.worker.base_url' => 'https://worker.test']);

    foreach (['updated' => 'updated_desc', 'created' => 'created_desc', 'title' => 'title_asc'] as $sort => $workerSort) {
        Http::fake(['worker.test/v1/orgs/*' => Http::response(['artifacts' => [], 'next_cursor' => null])]);

        callListArtifacts($token, ['sort' => $sort])->assertOk();

        Http::assertSent(fn (Request $request): bool => (listArtifactsWorkerQuery($request)['sort'] ?? null) === $workerSort);
    }

    Http::fake(['worker.test/v1/orgs/*' => Http::response(['artifacts' => [], 'next_cursor' => null])]);
    callListArtifacts($token, ['sort' => 'most_viewed'])->assertOk();

    // The ranking sort scans the newest artifacts the Worker can order.
    Http::assertSent(fn (Request $request): bool => ($query = listArtifactsWorkerQuery($request)) !== []
        && $query['sort'] === 'created_desc'
        && $query['limit'] === '200');
});

test('list_artifacts ranks most viewed and pages with a keyset cursor without duplicating', function () {
    $team = Team::factory()->create(['slug' => 'rub-440-most-viewed']);
    $token = remoteMcpToken($team);
    config(['services.worker.base_url' => 'https://worker.test']);

    $a = listArtifactsWorkerItem(['id' => 'aaaa111122233', 'updated_at' => '2026-10-03T00:00:00Z']);
    $b = listArtifactsWorkerItem(['id' => 'bbbb111122233', 'updated_at' => '2026-10-02T00:00:00Z']);
    $c = listArtifactsWorkerItem(['id' => 'cccc111122233', 'updated_at' => '2026-10-01T00:00:00Z']);
    $new = listArtifactsWorkerItem(['id' => 'dddd111122233', 'updated_at' => '2026-10-04T00:00:00Z']);

    listArtifactsRecordViews($team, 'aaaa111122233', 5);
    listArtifactsRecordViews($team, 'bbbb111122233', 3);
    listArtifactsRecordViews($team, 'cccc111122233', 1);

    $calls = 0;
    Http::fake(function () use (&$calls, $a, $b, $c, $new) {
        $calls++;
        $artifacts = $calls === 1 ? [$a, $b, $c] : [$new, $a, $b, $c];

        return Http::response(['artifacts' => $artifacts, 'next_cursor' => null]);
    });

    $first = callListArtifacts($token, ['sort' => 'most_viewed', 'limit' => 2])->assertOk();
    $firstIds = array_column($first->json('result.structuredContent.artifacts'), 'id');

    expect($firstIds)->toBe(['aaaa111122233', 'bbbb111122233'])
        ->and($first->json('result.structuredContent.artifacts.0.view_count'))->toBe(5);

    $cursor = $first->json('result.structuredContent.next_cursor');
    expect($cursor)->toBeString();

    $decoded = listArtifactsDecodeCursor($cursor);
    expect($decoded['s'])->toBe('most_viewed')
        ->and($decoded['v'])->toBe(3)
        ->and($decoded['id'])->toBe('bbbb111122233');

    // A new artifact is published between the pages; the keyset cursor neither
    // repeats a page-one item nor lets the newcomer leapfrog the ranking.
    $second = callListArtifacts($token, ['sort' => 'most_viewed', 'limit' => 2, 'cursor' => $cursor])->assertOk();
    $secondIds = array_column($second->json('result.structuredContent.artifacts'), 'id');

    expect($secondIds)->toBe(['cccc111122233', 'dddd111122233'])
        ->and(array_intersect($firstIds, $secondIds))->toBe([])
        ->and($second->json('result.structuredContent.next_cursor'))->toBeNull();
});

test('list_artifacts passes the Worker cursor through wrapped and ignores a cursor for another sort', function () {
    $team = Team::factory()->create(['slug' => 'rub-440-cursor']);
    $token = remoteMcpToken($team);
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake(['worker.test/v1/orgs/*' => Http::response(['artifacts' => [], 'next_cursor' => 'wc1'])]);

    $response = callListArtifacts($token)->assertOk();
    $cursor = $response->json('result.structuredContent.next_cursor');

    expect(listArtifactsDecodeCursor($cursor))->toBe(['s' => 'updated_desc', 'c' => 'wc1']);

    Http::fake(['worker.test/v1/orgs/*' => Http::response(['artifacts' => [], 'next_cursor' => null])]);
    callListArtifacts($token, ['cursor' => $cursor])->assertOk();

    Http::assertSent(fn (Request $request): bool => (listArtifactsWorkerQuery($request)['cursor'] ?? null) === 'wc1');

    // A cursor issued for a different sort is ignored rather than passed on.
    $mismatched = listArtifactsEncodeCursor(['s' => 'created_desc', 'c' => 'wc-from-another-sort']);
    Http::fake(['worker.test/v1/orgs/*' => Http::response(['artifacts' => [], 'next_cursor' => null])]);
    callListArtifacts($token, ['sort' => 'updated', 'cursor' => $mismatched])->assertOk();

    Http::assertSent(fn (Request $request): bool => ! isset(listArtifactsWorkerQuery($request)['cursor']));
});

test('list_artifacts returns view counts can_edit and owner you', function () {
    configureArtifactLinks();

    $team = Team::factory()->create(['slug' => 'rub-440-items']);
    $token = remoteMcpToken($team);
    $actor = OrgJwtService::default()->verify($token)['user_id'];
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake([
        'worker.test/v1/orgs/*' => Http::response([
            'artifacts' => [listArtifactsWorkerItem([
                'owner_user_id' => $actor,
                'can_edit' => true,
                'version' => 4,
                'kind' => 'markdown',
                'sharing' => 'team',
            ])],
            'next_cursor' => null,
        ]),
    ]);

    listArtifactsRecordViews($team, 'abc123def4567', 3);
    listArtifactsRecordViews($team, 'unrelated-artifact', 9);

    $item = callListArtifacts($token)->assertOk()->json('result.structuredContent.artifacts.0');

    expect($item['view_count'])->toBe(3)
        ->and($item['can_edit'])->toBeTrue()
        ->and($item['owner'])->toBe('you')
        ->and($item['agent'])->toBe('claude-code')
        ->and($item['version'])->toBe(4)
        ->and($item['kind'])->toBe('markdown')
        ->and($item['sharing'])->toBe('team')
        ->and($item['view_url'])->toBeString();
});

test('list_artifacts rejects a limit outside the allowed bounds before calling the Worker', function (array $arguments) {
    $team = Team::factory()->create();
    $token = remoteMcpToken($team);
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake();

    $response = callListArtifacts($token, $arguments)
        ->assertOk()
        ->assertJsonPath('result.isError', true);

    expect($response->json('result.content.0.text'))->toContain('limit');

    Http::assertNothingSent();
})->with([
    'zero' => [['limit' => 0]],
    'too large' => [['limit' => 51]],
]);

test('list_artifacts forwards the callers own bearer token to the Worker', function () {
    $team = Team::factory()->create(['slug' => 'rub-440-token']);
    $token = remoteMcpToken($team);
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake(['worker.test/v1/orgs/*' => Http::response(['artifacts' => [], 'next_cursor' => null])]);

    callListArtifacts($token)->assertOk();

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer '.$token));
});

test('list_artifacts maps a Worker 422 to invalid_request', function () {
    $team = Team::factory()->create();
    $token = remoteMcpToken($team);
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake(['worker.test/v1/orgs/*' => Http::response(['error' => ['code' => 'validation_failed']], 422)]);

    callListArtifacts($token)
        ->assertOk()
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0._meta.artfct.errorCode', 'invalid_request')
        ->assertJsonPath('result.content.0._meta.artfct.retryable', false);
});
