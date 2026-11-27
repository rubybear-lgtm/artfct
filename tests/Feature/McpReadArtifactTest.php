<?php

use App\Contracts\ArtifactContentSource;
use App\Enums\TeamRole;
use App\Mcp\Servers\ArtfctServer;
use App\Mcp\Tools\ReadArtifactTool;
use App\Models\ArtifactIndexEntry;
use App\Models\ArtifactIndexingFailure;
use App\Models\Team;
use App\Services\Auth\OrgJwtService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Mcp\Server\Transport\FakeTransporter;

/*
 * RUB-443 item 1: `read_artifact` returns the indexed, extracted text of one
 * artifact in bounded pages, marked untrusted. Authorization is the Worker's
 * (called with the caller's own token), so an artifact the caller cannot see
 * reads as not found before any text is touched.
 */

const READ_ARTIFACT_ID = 'abc123def4567';

/** Call `read_artifact` through the hosted MCP server. */
function callReadArtifact(string $token, array $arguments): TestResponse
{
    return test()->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => ['name' => 'read_artifact', 'arguments' => $arguments],
    ]);
}

/** Index text for an artifact in a team and fake the Worker's metadata answer. */
function readArtifactFixture(Team $team, string $text, array $entry = [], int $workerStatus = 200, array $worker = []): void
{
    config(['services.worker.base_url' => 'https://worker.test', 'indexing.enabled' => true]);
    ArtifactIndexEntry::factory()->create(array_merge([
        'team_id' => $team->id,
        'artifact_id' => READ_ARTIFACT_ID,
        'extracted_text' => $text,
        'title' => 'Migration plan',
        'headings' => ['Overview'],
        'version' => 3,
    ], $entry));
    Http::fake(['worker.test/v1/artifacts/*' => Http::response([
        'id' => READ_ARTIFACT_ID,
        'title' => 'Migration plan',
        'version' => 3,
        'provenance' => ['agent' => 'claude-code', 'repo_url' => null, 'commit_sha' => null],
        ...$worker,
    ], $workerStatus)]);
}

test('read_artifact returns untrusted-marked text with a locator', function () {
    $team = Team::factory()->create(['slug' => 'rub-443-read']);
    $token = remoteMcpToken($team);
    readArtifactFixture($team, 'The cutover happens on Friday.');

    $response = callReadArtifact($token, ['id' => READ_ARTIFACT_ID])
        ->assertOk()
        ->assertJsonPath('result.isError', false);

    $content = $response->json('result.structuredContent');
    expect($content['content']['untrusted'])->toBeTrue()
        ->and($content['content']['text'])->toBe('The cutover happens on Friday.')
        ->and($content['content']['notice'])->not->toBeEmpty()
        ->and($content['indexed_version'])->toBe(3)
        ->and($content['current_version'])->toBe(3)
        ->and($content['stale'])->toBeFalse()
        ->and($content['title'])->toBe('Migration plan')
        ->and($content['locator'])->toBe(['offset' => 0, 'end_offset' => 30, 'total_characters' => 30])
        ->and($content['next_offset'])->toBeNull();
});

test('read_artifact pages with a next offset and never exceeds the per-call cap', function () {
    $team = Team::factory()->create(['slug' => 'rub-443-paging']);
    $token = remoteMcpToken($team);
    readArtifactFixture($team, str_repeat('a', 20000));

    $first = callReadArtifact($token, ['id' => READ_ARTIFACT_ID, 'limit' => 8000])
        ->assertOk()->json('result.structuredContent');

    expect(mb_strlen($first['content']['text']))->toBe(ReadArtifactTool::MAX_LIMIT)
        ->and($first['next_offset'])->toBe(8000);

    $last = callReadArtifact($token, ['id' => READ_ARTIFACT_ID, 'offset' => 16000, 'limit' => 8000])
        ->assertOk()->json('result.structuredContent');

    expect(mb_strlen($last['content']['text']))->toBe(4000)
        ->and($last['next_offset'])->toBeNull();

    callReadArtifact($token, ['id' => READ_ARTIFACT_ID, 'limit' => 8001])
        ->assertOk()
        ->assertJsonPath('result.isError', true);
});

test('read_artifact defaults to a bounded page', function () {
    $team = Team::factory()->create(['slug' => 'rub-443-default']);
    $token = remoteMcpToken($team);
    readArtifactFixture($team, str_repeat('b', 10000));

    $page = callReadArtifact($token, ['id' => READ_ARTIFACT_ID])->assertOk()->json('result.structuredContent');

    expect(mb_strlen($page['content']['text']))->toBe(ReadArtifactTool::DEFAULT_LIMIT)
        ->and($page['next_offset'])->toBe(ReadArtifactTool::DEFAULT_LIMIT);
});

test('read_artifact reads an artifact the Worker hides as not found without touching text', function () {
    $team = Team::factory()->create(['slug' => 'rub-443-hidden']);
    $token = remoteMcpToken($team);
    readArtifactFixture($team, 'secret text', workerStatus: 404);

    $response = callReadArtifact($token, ['id' => READ_ARTIFACT_ID])
        ->assertOk()
        ->assertJsonPath('result.isError', true);

    expect($response->json('result.content.0._meta.artfct.errorCode'))->toBe('artifact_not_found')
        ->and($response->getContent())->not->toContain('secret text');
});

test('read_artifact never serves another workspace\'s indexed text', function () {
    $team = Team::factory()->create(['slug' => 'rub-443-own']);
    $other = Team::factory()->create(['slug' => 'rub-443-other']);
    $token = remoteMcpToken($team);
    readArtifactFixture($other, 'other workspace text');

    $response = callReadArtifact($token, ['id' => READ_ARTIFACT_ID])->assertOk();

    expect($response->json('result.isError'))->toBeTrue()
        ->and($response->json('result.content.0._meta.artfct.errorCode'))->toBe('content_not_indexed')
        ->and($response->getContent())->not->toContain('other workspace text');
});

test('read_artifact reports a version that was not indexed', function () {
    $team = Team::factory()->create(['slug' => 'rub-443-version']);
    $token = remoteMcpToken($team);
    readArtifactFixture($team, 'text');

    $response = callReadArtifact($token, ['id' => READ_ARTIFACT_ID, 'version' => 2])->assertOk();

    expect($response->json('result.content.0._meta.artfct.errorCode'))->toBe('version_not_indexed');
});

test('read_artifact forwards the caller token to the Worker', function () {
    $team = Team::factory()->create(['slug' => 'rub-443-token']);
    $token = remoteMcpToken($team);
    readArtifactFixture($team, 'text');

    callReadArtifact($token, ['id' => READ_ARTIFACT_ID])->assertOk();

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer '.$token)
        && str_ends_with($request->url(), '/v1/artifacts/'.READ_ARTIFACT_ID));
});

test('read_artifact serves the live version when the index lags', function () {
    $team = Team::factory()->create(['slug' => 'rub-443-stale']);
    $token = remoteMcpToken($team);
    readArtifactFixture($team, 'version one text', ['version' => 1], worker: ['version' => 3]);
    app(ArtifactContentSource::class)->seed('rub-443-stale', READ_ARTIFACT_ID, '<!doctype html><html><head><title>Migration plan</title></head><body><h1>Migration plan</h1><p>Version three text with enough visible words to skip the render pass entirely here.</p></body></html>', ['agent' => 'claude-code', 'repo_url' => null, 'commit_sha' => null], version: 3);

    $content = callReadArtifact($token, ['id' => READ_ARTIFACT_ID])->assertOk()->json('result.structuredContent');

    expect($content['content']['text'])->toContain('Version three text')
        ->and($content['indexed_version'])->toBe(3)
        ->and($content['current_version'])->toBe(3)
        ->and($content['stale'])->toBeFalse();

    $explicit = callReadArtifact($token, ['id' => READ_ARTIFACT_ID, 'version' => 3])->assertOk()->json('result.structuredContent');
    expect($explicit['content']['text'])->toContain('Version three text')
        ->and($explicit['indexed_version'])->toBe(3);
});

test('read_artifact returns the current version after a design system update', function () {
    $team = Team::factory()->create(['slug' => 'rub-591-design']);
    $token = remoteMcpToken($team);
    readArtifactFixture($team, 'Ink is our only accent.', ['version' => 1], worker: ['version' => 2]);

    $v2Html = '<!doctype html><html><head><title>Sample design system</title></head><body><h1>Sample design system</h1><p>Ink is our only accent. Paper joins it in version two with expanded tokens.</p></body></html>';
    app(ArtifactContentSource::class)->seed('rub-591-design', READ_ARTIFACT_ID, $v2Html, ['agent' => 'claude-code', 'repo_url' => null, 'commit_sha' => null], version: 2);

    $content = callReadArtifact($token, ['id' => READ_ARTIFACT_ID])->assertOk()->json('result.structuredContent');

    expect($content['content']['text'])->toContain('Paper joins it in version two')
        ->and($content['indexed_version'])->toBe(2)
        ->and($content['current_version'])->toBe(2)
        ->and($content['stale'])->toBeFalse();

    $v1 = callReadArtifact($token, ['id' => READ_ARTIFACT_ID, 'version' => 1])->assertOk()->json('result.structuredContent');
    expect($v1['content']['text'])->toBe('Ink is our only accent.')
        ->and($v1['indexed_version'])->toBe(1)
        ->and($v1['stale'])->toBeTrue();
});

test('read_artifact refuses private live text to a caller who cannot view it', function () {
    $team = Team::factory()->create(['slug' => 'rub-591-private-live']);
    $owner = memberOfTeam($team, TeamRole::Member);
    $token = remoteMcpToken($team, TeamRole::Member);
    readArtifactFixture($team, 'old indexed text stays hidden', ['version' => 1], worker: ['version' => 2]);
    app(ArtifactContentSource::class)->seed('rub-591-private-live', READ_ARTIFACT_ID, '<!doctype html><html><head><title>Private plan</title></head><body><h1>Private plan</h1><p>Private live text with enough visible words to skip the render pass entirely here.</p></body></html>', ['agent' => 'claude-code', 'repo_url' => null, 'commit_sha' => null], version: 2, sharing: 'private', ownerUserId: (string) $owner->id);

    $response = callReadArtifact($token, ['id' => READ_ARTIFACT_ID])->assertOk();

    expect($response->json('result.isError'))->toBeTrue()
        ->and($response->json('result.content.0._meta.artfct.errorCode'))->toBe('artifact_not_found')
        ->and($response->getContent())->not->toContain('old indexed text')
        ->and($response->getContent())->not->toContain('Private live text');
});

test('read_artifact serves private live text to the owning member', function () {
    $team = Team::factory()->create(['slug' => 'rub-591-private-owner']);
    $owner = memberOfTeam($team, TeamRole::Member);
    configureSigning(testSigningKey());
    $token = OrgJwtService::default()->mint($team, $owner, TeamRole::Member)['token'];
    readArtifactFixture($team, 'old indexed text', ['version' => 1], worker: ['version' => 2]);
    app(ArtifactContentSource::class)->seed('rub-591-private-owner', READ_ARTIFACT_ID, '<!doctype html><html><head><title>Private plan</title></head><body><h1>Private plan</h1><p>Private live text with enough visible words to skip the render pass entirely here.</p></body></html>', ['agent' => 'claude-code', 'repo_url' => null, 'commit_sha' => null], version: 2, sharing: 'private', ownerUserId: (string) $owner->id);

    $content = callReadArtifact($token, ['id' => READ_ARTIFACT_ID])->assertOk()->json('result.structuredContent');

    expect($content['content']['text'])->toContain('Private live text')
        ->and($content['indexed_version'])->toBe(2)
        ->and($content['stale'])->toBeFalse();
});

test('read_artifact serves private live text to a team admin', function () {
    $team = Team::factory()->create(['slug' => 'rub-591-private-admin']);
    $owner = memberOfTeam($team, TeamRole::Member);
    $token = remoteMcpToken($team);
    readArtifactFixture($team, 'old indexed text', ['version' => 1], worker: ['version' => 2]);
    app(ArtifactContentSource::class)->seed('rub-591-private-admin', READ_ARTIFACT_ID, '<!doctype html><html><head><title>Private plan</title></head><body><h1>Private plan</h1><p>Private live text with enough visible words to skip the render pass entirely here.</p></body></html>', ['agent' => 'claude-code', 'repo_url' => null, 'commit_sha' => null], version: 2, sharing: 'private', ownerUserId: (string) $owner->id);

    $content = callReadArtifact($token, ['id' => READ_ARTIFACT_ID])->assertOk()->json('result.structuredContent');

    expect($content['content']['text'])->toContain('Private live text')
        ->and($content['indexed_version'])->toBe(2)
        ->and($content['stale'])->toBeFalse();
});

test('read_artifact fails closed when the live fetch reports no sharing', function () {
    $team = Team::factory()->create(['slug' => 'rub-591-sharing-change']);
    $token = remoteMcpToken($team, TeamRole::Member);
    readArtifactFixture($team, 'old indexed text stays hidden', ['version' => 1], worker: ['version' => 2]);
    // The metadata read came back readable, but by content-fetch time the
    // artifact reports no sharing or owner: fail closed, leak nothing.
    app()->instance(ArtifactContentSource::class, new class implements ArtifactContentSource
    {
        public function fetch(string $orgSlug, string $artifactId, ?int $timeoutSeconds = null): ?array
        {
            return [
                'html' => '<!doctype html><html><body><p>Changed live text with enough visible words to skip the render pass entirely here.</p></body></html>',
                'tier' => 'secure',
                'sharing' => null,
                'owner_user_id' => null,
                'version' => 2,
                'entrypoint' => 'index.html',
                'files' => null,
                'provenance' => ['agent' => null, 'repo_url' => null, 'commit_sha' => null],
            ];
        }
    });

    $response = callReadArtifact($token, ['id' => READ_ARTIFACT_ID])->assertOk();

    expect($response->json('result.isError'))->toBeTrue()
        ->and($response->json('result.content.0._meta.artfct.errorCode'))->toBe('artifact_not_found')
        ->and($response->getContent())->not->toContain('old indexed text')
        ->and($response->getContent())->not->toContain('Changed live text');
});

test('read_artifact falls back to indexed text when a publish lands mid-read', function () {
    $team = Team::factory()->create(['slug' => 'rub-591-midread']);
    $token = remoteMcpToken($team);
    readArtifactFixture($team, 'version one text', ['version' => 1], worker: ['version' => 2]);
    app(ArtifactContentSource::class)->seed('rub-591-midread', READ_ARTIFACT_ID, '<!doctype html><html><head><title>Migration plan</title></head><body><h1>Migration plan</h1><p>Version three text with enough visible words to skip the render pass entirely here.</p></body></html>', ['agent' => 'claude-code', 'repo_url' => null, 'commit_sha' => null], version: 3);

    $content = callReadArtifact($token, ['id' => READ_ARTIFACT_ID])->assertOk()->json('result.structuredContent');

    expect($content['content']['text'])->toBe('version one text')
        ->and($content['indexed_version'])->toBe(1)
        ->and($content['current_version'])->toBe(2)
        ->and($content['stale'])->toBeTrue();
});

test('read_artifact falls back to indexed text when the live page needs a render pass', function () {
    $team = Team::factory()->create(['slug' => 'rub-591-shell']);
    $token = remoteMcpToken($team);
    readArtifactFixture($team, 'version one text', ['version' => 1], worker: ['version' => 2]);
    app(ArtifactContentSource::class)->seed('rub-591-shell', READ_ARTIFACT_ID, '<!doctype html><html><head><title>App</title></head><body><div id="root"></div><script src="/assets/app.js"></script></body></html>', ['agent' => 'claude-code', 'repo_url' => null, 'commit_sha' => null], version: 2);

    $content = callReadArtifact($token, ['id' => READ_ARTIFACT_ID])->assertOk()->json('result.structuredContent');

    expect($content['content']['text'])->toBe('version one text')
        ->and($content['indexed_version'])->toBe(1)
        ->and($content['current_version'])->toBe(2)
        ->and($content['stale'])->toBeTrue();
});

test('read_artifact falls back to indexed text when the live fetch throws', function () {
    $team = Team::factory()->create(['slug' => 'rub-591-throw']);
    $token = remoteMcpToken($team);
    readArtifactFixture($team, 'version one text', ['version' => 1], worker: ['version' => 2]);
    app()->instance(ArtifactContentSource::class, new class implements ArtifactContentSource
    {
        public function fetch(string $orgSlug, string $artifactId, ?int $timeoutSeconds = null): ?array
        {
            throw new RuntimeException('Worker down');
        }
    });

    $content = callReadArtifact($token, ['id' => READ_ARTIFACT_ID])->assertOk()->json('result.structuredContent');

    expect($content['content']['text'])->toBe('version one text')
        ->and($content['indexed_version'])->toBe(1)
        ->and($content['current_version'])->toBe(2)
        ->and($content['stale'])->toBeTrue();
});

test('read_artifact falls back to indexed text when the live page is oversized', function () {
    $team = Team::factory()->create(['slug' => 'rub-591-oversize']);
    $token = remoteMcpToken($team);
    readArtifactFixture($team, 'version one text', ['version' => 1], worker: ['version' => 2]);
    $big = '<p>'.str_repeat('lorem ipsum dolor sit amet ', 100000).'</p>';
    app(ArtifactContentSource::class)->seed('rub-591-oversize', READ_ARTIFACT_ID, $big, ['agent' => 'claude-code', 'repo_url' => null, 'commit_sha' => null], version: 2);

    $content = callReadArtifact($token, ['id' => READ_ARTIFACT_ID])->assertOk()->json('result.structuredContent');

    expect($content['content']['text'])->toBe('version one text')
        ->and($content['indexed_version'])->toBe(1)
        ->and($content['current_version'])->toBe(2)
        ->and($content['stale'])->toBeTrue();
});

test('read_artifact bounds the live fetch below the indexer timeout', function () {
    $team = Team::factory()->create(['slug' => 'rub-591-timeout']);
    $token = remoteMcpToken($team);
    readArtifactFixture($team, 'version one text', ['version' => 1], worker: ['version' => 2]);
    $spy = new class implements ArtifactContentSource
    {
        /** @var list<array{string, string, ?int}> */
        public array $calls = [];

        public function fetch(string $orgSlug, string $artifactId, ?int $timeoutSeconds = null): ?array
        {
            $this->calls[] = [$orgSlug, $artifactId, $timeoutSeconds];

            return null;
        }
    };
    app()->instance(ArtifactContentSource::class, $spy);

    callReadArtifact($token, ['id' => READ_ARTIFACT_ID])->assertOk();

    expect($spy->calls)->toHaveCount(1)
        ->and($spy->calls[0][2])->not->toBeNull()
        ->and($spy->calls[0][2])->toBeLessThan(15);
});

test('read_artifact refuses a version request when the indexed version is unknown', function () {
    $team = Team::factory()->create(['slug' => 'rub-443-unknown-version']);
    $token = remoteMcpToken($team);
    readArtifactFixture($team, 'text', ['version' => null]);

    $response = callReadArtifact($token, ['id' => READ_ARTIFACT_ID, 'version' => 3])->assertOk();

    expect($response->json('result.content.0._meta.artfct.errorCode'))->toBe('version_not_indexed');
});

test('read_artifact treats a revoked artifact and a forbidden one as not found', function (int $status, array $worker) {
    $team = Team::factory()->create(['slug' => 'rub-443-revoked-'.$status]);
    $token = remoteMcpToken($team);
    readArtifactFixture($team, 'revoked text', workerStatus: $status, worker: $worker);

    $response = callReadArtifact($token, ['id' => READ_ARTIFACT_ID])->assertOk();

    expect($response->json('result.isError'))->toBeTrue()
        ->and($response->json('result.content.0._meta.artfct.errorCode'))->toBe('artifact_not_found')
        ->and($response->getContent())->not->toContain('revoked text');
})->with([
    'revoked (the Worker answers 404)' => [404, []],
    'forbidden' => [403, []],
]);

test('read_artifact does not invite a retry for a dead-lettered artifact', function () {
    $team = Team::factory()->create(['slug' => 'rub-443-dead']);
    $token = remoteMcpToken($team);
    config(['services.worker.base_url' => 'https://worker.test', 'indexing.enabled' => true]);
    Http::fake(['worker.test/v1/artifacts/*' => Http::response(['id' => READ_ARTIFACT_ID, 'version' => 1])]);
    ArtifactIndexingFailure::query()->create([
        'team_id' => $team->id,
        'artifact_id' => READ_ARTIFACT_ID,
        'attempts' => 3,
        'reason' => 'render timeout',
        'failed_at' => now(),
    ]);

    $response = callReadArtifact($token, ['id' => READ_ARTIFACT_ID])->assertOk();

    expect($response->json('result.content.0._meta.artfct.errorCode'))->toBe('content_not_available')
        ->and($response->json('result.content.0._meta.artfct.retryable'))->toBeFalse();
});

test('read_artifact says reading is not enabled when indexing is off', function () {
    $team = Team::factory()->create(['slug' => 'rub-443-off']);
    $token = remoteMcpToken($team);
    config(['indexing.enabled' => false]);

    $response = callReadArtifact($token, ['id' => READ_ARTIFACT_ID])->assertOk();

    expect($response->json('result.content.0._meta.artfct.errorCode'))->toBe('search_not_configured');
});

test('read_artifact returns headings provenance and strips a duplicated title', function () {
    $team = Team::factory()->create(['slug' => 'rub-515-read-meta']);
    $token = remoteMcpToken($team);
    config(['services.worker.base_url' => 'https://worker.test', 'indexing.enabled' => true]);
    ArtifactIndexEntry::factory()->create([
        'team_id' => $team->id,
        'artifact_id' => READ_ARTIFACT_ID,
        'extracted_text' => 'Migration plan The cutover happens on Friday.',
        'title' => 'Migration plan',
        'headings' => ['Overview', 'Timeline'],
        'version' => 3,
    ]);
    // Worker metadata omits provenance; fall back to the version record.
    // Exact URL stubs must not use a prefix that also matches /versions/N.
    Http::fake(function (Request $request) {
        $url = $request->url();
        if (str_ends_with($url, '/versions/3')) {
            return Http::response([
                'version' => 3,
                'agent' => 'claude-code',
                'title' => 'Migration plan',
                'created_at' => '2026-10-01T00:00:00Z',
                'created_by' => 'user_1',
                'current' => true,
                'restored_from' => null,
                'description' => null,
                'content_hash' => str_repeat('a', 64),
            ], 200);
        }

        if (str_ends_with($url, '/v1/artifacts/'.READ_ARTIFACT_ID)) {
            return Http::response([
                'id' => READ_ARTIFACT_ID,
                'title' => 'Migration plan',
                'version' => 3,
            ], 200);
        }

        return Http::response(['error' => 'unexpected'], 500);
    });

    $content = callReadArtifact($token, ['id' => READ_ARTIFACT_ID])->assertOk()->json('result.structuredContent');

    expect($content['headings'])->toBe(['Overview', 'Timeline'])
        ->and($content['provenance']['agent'])->toBe('claude-code')
        ->and($content['title'])->toBe('Migration plan')
        ->and($content['content']['text'])->toBe('The cutover happens on Friday.')
        ->and($content['content']['text'])->not->toStartWith('Migration plan');
});

test('read_artifact documents the live current-version path and the served version', function () {
    $server = app(ArtfctServer::class, ['transport' => new FakeTransporter]);
    $server->start();
    $tool = $server->createContext()->tools()->first(fn ($tool): bool => $tool->name() === 'read_artifact')->toArray();

    expect($tool['description'])->toContain('reads the current version')
        ->and($tool['description'])->toContain('indexed_version names the version the returned text came from')
        ->and(json_encode($tool['inputSchema']))->toContain('older versions are not available');
});
