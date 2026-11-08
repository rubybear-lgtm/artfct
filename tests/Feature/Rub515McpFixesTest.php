<?php

use App\Mcp\Servers\ArtfctServer;
use App\Mcp\Support\McpTimestamp;
use App\Models\ArtifactIndexEntry;
use App\Models\Collection;
use App\Models\CollectionArtifact;
use App\Models\Team;
use App\Services\Artifacts\ArtifactAccessLink;
use App\Services\Artifacts\ArtifactViewLink;
use App\Services\Billing\FakeUsage;
use App\Services\Billing\UsageContract;
use App\Services\Collections\CollectionService;
use App\Services\Indexing\ArtifactTextPurger;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Mcp\Server\Transport\FakeTransporter;

const RUB515_ARTIFACT_ID = 'abc123def4567';

function callRub515Tool(string $token, string $tool, array $arguments = []): TestResponse
{
    return test()->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => ['name' => $tool, 'arguments' => $arguments],
    ]);
}

test('list_artifacts returns invalid_cursor for a garbage cursor instead of page 1', function () {
    $team = Team::factory()->create(['slug' => 'rub-515-list-cursor']);
    $token = remoteMcpToken($team);
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake([
        'worker.test/v1/orgs/*/artifacts*' => Http::response([
            'artifacts' => [['id' => RUB515_ARTIFACT_ID, 'title' => 'Should not list']],
            'next_cursor' => null,
        ]),
    ]);

    $response = callRub515Tool($token, 'list_artifacts', ['cursor' => 'not-a-cursor!!!'])->assertOk();

    expect($response->json('result.isError'))->toBeTrue()
        ->and($response->json('result.content.0._meta.artfct.errorCode'))->toBe('invalid_cursor')
        ->and($response->json('result.content.0.text'))->toContain('cursor');

    Http::assertNothingSent();
});

test('search_artifacts returns collection_not_found for an unknown collection like list_artifacts', function () {
    config(['indexing.enabled' => true]);
    $team = Team::factory()->create(['slug' => 'rub-515-search-collection']);
    $token = remoteMcpToken($team);

    $response = callRub515Tool($token, 'search_artifacts', [
        'query' => 'billing',
        'collection' => 'does-not-exist',
    ])->assertOk();

    expect($response->json('result.isError'))->toBeTrue()
        ->and($response->json('result.content.0._meta.artfct.errorCode'))->toBe('collection_not_found')
        ->and($response->json('result.content.0.text'))->toContain('collection');
});

test('search_artifacts accepts a collection numeric id and returns a hint when empty', function () {
    config(['indexing.enabled' => true]);
    $team = Team::factory()->create(['slug' => 'rub-515-search-hint']);
    $token = remoteMcpToken($team);
    $collection = Collection::factory()->create(['team_id' => $team->id, 'name' => 'Empty shelf']);

    $response = callRub515Tool($token, 'search_artifacts', [
        'query' => 'zzzz-no-such-topic-ever',
        'collection' => (string) $collection->id,
    ])->assertOk();

    expect($response->json('result.isError'))->toBeFalse()
        ->and($response->json('result.structuredContent.results'))->toBe([])
        ->and($response->json('result.structuredContent.hint'))->toBeString()
        ->and($response->json('result.structuredContent.hint'))->toContain('No artifacts matched');
});

test('delete_artifact forgets collection memberships and purges the index entry', function () {
    $team = Team::factory()->create(['slug' => 'rub-515-delete-purge']);
    $token = remoteMcpToken($team);
    $collection = Collection::factory()->create(['team_id' => $team->id]);
    CollectionArtifact::create([
        'collection_id' => $collection->id,
        'artifact_id' => RUB515_ARTIFACT_ID,
        'added_at' => now(),
    ]);
    ArtifactIndexEntry::factory()->create([
        'team_id' => $team->id,
        'artifact_id' => RUB515_ARTIFACT_ID,
    ]);
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake([
        'worker.test/v1/artifacts/'.RUB515_ARTIFACT_ID => Http::response(['deleted' => true], 200),
    ]);

    callRub515Tool($token, 'delete_artifact', ['id' => RUB515_ARTIFACT_ID])
        ->assertOk()
        ->assertJsonPath('result.structuredContent.deleted', true);

    expect(CollectionArtifact::query()->where('artifact_id', RUB515_ARTIFACT_ID)->exists())->toBeFalse()
        ->and(ArtifactIndexEntry::query()->where('team_id', $team->id)->where('artifact_id', RUB515_ARTIFACT_ID)->exists())->toBeFalse();
});

test('get_connection last_indexed_at uses the shared UTC Z timestamp format', function () {
    config(['indexing.enabled' => true]);
    $team = Team::factory()->create();
    $latest = ArtifactIndexEntry::factory()->create([
        'team_id' => $team->id,
        'extracted_at' => '2026-10-18 12:00:00',
    ]);
    $token = remoteMcpToken($team);

    $initialize = test()->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => ['protocolVersion' => '2025-11-25'],
    ])->assertOk();

    $search = test()->withToken($token)
        ->withHeader('MCP-Session-Id', $initialize->headers->get('MCP-Session-Id'))
        ->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => ['name' => 'get_connection', 'arguments' => []],
        ])->assertOk()
        ->json('result.structuredContent.search');

    expect($search['last_indexed_at'])->toBe(McpTimestamp::format($latest->extracted_at))
        ->and($search['last_indexed_at'])->toMatch(McpTimestamp::PATTERN);
});

test('collection service forgetArtifact drops memberships across the team', function () {
    $team = Team::factory()->create();
    $a = Collection::factory()->create(['team_id' => $team->id]);
    $b = Collection::factory()->create(['team_id' => $team->id]);
    CollectionArtifact::create(['collection_id' => $a->id, 'artifact_id' => RUB515_ARTIFACT_ID, 'added_at' => now()]);
    CollectionArtifact::create(['collection_id' => $b->id, 'artifact_id' => RUB515_ARTIFACT_ID, 'added_at' => now()]);

    app(CollectionService::class)->forgetArtifact($team, RUB515_ARTIFACT_ID);

    expect(CollectionArtifact::query()->where('artifact_id', RUB515_ARTIFACT_ID)->count())->toBe(0);
});

test('artifact text purger removes the index entry used by list_collections counts', function () {
    $team = Team::factory()->create();
    ArtifactIndexEntry::factory()->create(['team_id' => $team->id, 'artifact_id' => RUB515_ARTIFACT_ID]);

    ArtifactTextPurger::purgeEverything($team, RUB515_ARTIFACT_ID);

    expect(ArtifactIndexEntry::query()->where('artifact_id', RUB515_ARTIFACT_ID)->exists())->toBeFalse();
});

test('get_usage and get_connection reflect create then delete within one indexing cycle', function () {
    config(['indexing.enabled' => true, 'services.worker.base_url' => 'https://worker.test']);
    $team = Team::factory()->create(['slug' => 'rub-515-live-counts']);
    $token = remoteMcpToken($team);
    $collection = Collection::factory()->create(['team_id' => $team->id, 'name' => 'Live counts']);
    CollectionArtifact::create([
        'collection_id' => $collection->id,
        'artifact_id' => RUB515_ARTIFACT_ID,
        'added_at' => now(),
    ]);
    ArtifactIndexEntry::factory()->create([
        'team_id' => $team->id,
        'artifact_id' => RUB515_ARTIFACT_ID,
        'extracted_at' => '2026-10-18 12:00:00',
    ]);

    $usage = new FakeUsage;
    $usage->setUsage($team->slug, storageBytes: 100, artifactsThisPeriod: 1);
    app()->instance(UsageContract::class, $usage);
    Http::fake([
        'worker.test/v1/artifacts/'.RUB515_ARTIFACT_ID => Http::response(['deleted' => true], 200),
    ]);

    $beforeUsage = callRub515Tool($token, 'get_usage')->assertOk()->json('result.structuredContent.artifacts.used');
    $beforeIndexed = callRub515Tool($token, 'get_connection')->assertOk()->json('result.structuredContent.search.last_indexed_at');
    $beforeCount = callRub515Tool($token, 'list_collections')->assertOk()->json('result.structuredContent.collections.0.artifact_count');

    expect($beforeUsage)->toBe(1)
        ->and($beforeIndexed)->toBe('2026-10-18T12:00:00Z')
        ->and($beforeCount)->toBe(1);

    callRub515Tool($token, 'delete_artifact', ['id' => RUB515_ARTIFACT_ID])->assertOk();
    $usage->setUsage($team->slug, storageBytes: 0, artifactsThisPeriod: 0);

    ArtifactIndexEntry::factory()->create([
        'team_id' => $team->id,
        'artifact_id' => 'zzz999yyy8887',
        'extracted_at' => '2026-10-19 15:30:00',
    ]);

    $afterUsage = callRub515Tool($token, 'get_usage')->assertOk()->json('result.structuredContent.artifacts.used');
    $afterIndexed = callRub515Tool($token, 'get_connection')->assertOk()->json('result.structuredContent.search.last_indexed_at');
    $afterCount = callRub515Tool($token, 'list_collections')->assertOk()->json('result.structuredContent.collections.0.artifact_count');

    expect($afterUsage)->toBe(0)
        ->and($afterCount)->toBe(0)
        ->and($afterIndexed)->toBe('2026-10-19T15:30:00Z')
        ->and($afterIndexed)->not->toBe($beforeIndexed);
});

test('artifact view and access links never use the bare legacy id--prod host', function () {
    configureArtifactLinks('test-secret', '--prod.artfct.dev');
    config(['app.public_base_url' => 'https://artfct.dev']);

    $view = ArtifactViewLink::forArtifact('acme', RUB515_ARTIFACT_ID, 'secure');
    $access = ArtifactAccessLink::default()->forArtifact('acme', RUB515_ARTIFACT_ID);

    expect($view)->toBeString()
        ->and($view)->toContain('/a/'.RUB515_ARTIFACT_ID)
        ->and($view)->not->toContain(RUB515_ARTIFACT_ID.'--prod.artfct.dev')
        ->and($access)->toBeString()
        ->and(str_contains((string) $access, 'acme--'.RUB515_ARTIFACT_ID.'--prod.artfct.dev'))->toBeTrue()
        ->and(str_contains((string) $access, 'https://'.RUB515_ARTIFACT_ID.'--prod'))->toBeFalse();

    $sources = [
        (string) file_get_contents(base_path('app/Services/Artifacts/ArtifactViewLink.php')),
        (string) file_get_contents(base_path('app/Services/Artifacts/ArtifactAccessLink.php')),
        (string) file_get_contents(base_path('app/Mcp/Support/McpArtifactLink.php')),
    ];
    foreach ($sources as $source) {
        expect($source)->not->toContain('{artifact_id}--prod')
            ->and($source)->not->toContain('{artifactId}--prod')
            ->and($source)->not->toContain('legacy host');
    }
});

test('mcp tool timestamps match the shared Zulu pattern', function () {
    configureArtifactLinks();
    $team = Team::factory()->create();
    $token = remoteMcpToken($team);
    config(['services.worker.base_url' => 'https://worker.test', 'indexing.enabled' => true]);
    Http::fake([
        'worker.test/v1/artifacts/'.RUB515_ARTIFACT_ID => Http::response([
            'id' => RUB515_ARTIFACT_ID,
            'tier' => 'secure',
            'sharing' => 'team',
            'entrypoint' => 'index.html',
            'created_at' => '2026-10-01T12:00:00.618+00:00',
            'updated_at' => '2026-10-02T13:30:45.123+00:00',
            'expires_at' => null,
            'title' => 'Stamp check',
            'description' => 'desc',
            'version' => 2,
            'version_count' => 2,
        ], 200),
        'worker.test/v1/orgs/*' => Http::response([
            'artifacts' => [[
                'id' => RUB515_ARTIFACT_ID,
                'title' => 'Stamp check',
                'sharing' => 'team',
                'created_at' => '2026-10-01T12:00:00.618+00:00',
                'updated_at' => '2026-10-02T13:30:45.123+00:00',
                'version' => 2,
                'kind' => 'html',
            ]],
            'next_cursor' => null,
        ], 200),
    ]);

    $getResponse = callRub515Tool($token, 'get_artifact', ['id' => RUB515_ARTIFACT_ID])->assertOk();
    expect($getResponse->json('result.isError'))->toBeFalse();
    $get = $getResponse->json('result.structuredContent');

    $listResponse = callRub515Tool($token, 'list_artifacts')->assertOk();
    expect($listResponse->json('result.isError'))->toBeFalse();
    $list = $listResponse->json('result.structuredContent.artifacts.0');

    $collection = callRub515Tool($token, 'create_collection', ['name' => 'Stamp shelf'])
        ->assertOk()
        ->json('result.structuredContent');

    expect($get)->toBeArray()
        ->and($list)->toBeArray()
        ->and($collection)->toBeArray();

    foreach ([
        $get['created_at'] ?? null,
        $get['updated_at'] ?? null,
        $list['created_at'] ?? null,
        $list['updated_at'] ?? null,
        $collection['created_at'] ?? null,
        $collection['updated_at'] ?? null,
    ] as $stamp) {
        expect($stamp)->toMatch(McpTimestamp::PATTERN);
    }
});

test('tool descriptions cover omit-fields collection name-or-id both-given and reply-to-reply', function () {
    $server = app(ArtfctServer::class, ['transport' => new FakeTransporter]);
    $server->start();
    $tools = $server->createContext()->tools()->mapWithKeys(
        fn ($tool): array => [$tool->name() => $tool->toArray()]
    );

    expect($tools['deploy_artifact']['description'])->toContain('omitted fields')
        ->and($tools['deploy_artifact']['description'])->toContain('keep their previous values')
        ->and($tools['search_artifacts']['inputSchema']['properties']['collection']['description'])->toContain('name or numeric id')
        ->and($tools['add_collection_artifact']['description'])->toContain('both are given')
        ->and($tools['add_collection_artifact']['description'])->not->toContain("'Approved' is not a separate status")
        ->and($tools['add_artifact_comment']['description'])->toContain('reply to a reply')
        ->and($tools['read_artifact']['description'].json_encode($tools['read_artifact']['inputSchema']))->toContain('indexed');
});
