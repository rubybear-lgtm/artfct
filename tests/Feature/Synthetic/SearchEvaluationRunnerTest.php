<?php

use App\Models\ArtifactChunk;
use App\Models\ArtifactIndexEntry;
use App\Models\Team;
use App\Services\Indexing\Chunker;
use App\Services\Synthetic\SearchEvaluationClient;
use App\Services\Synthetic\SearchEvaluationDataset;
use App\Services\Synthetic\SearchEvaluationManifest;
use App\Services\Synthetic\SearchEvaluationReadiness;
use App\Services\Synthetic\SearchEvaluationRunner;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

function evaluationDataset(): SearchEvaluationDataset
{
    return SearchEvaluationDataset::load(base_path('tests/Fixtures/synthetic/search-evaluation.json'));
}

function evaluationManifest(SearchEvaluationDataset $dataset): SearchEvaluationManifest
{
    $path = tempnam(sys_get_temp_dir(), 'rub442-manifest-');
    File::delete($path);
    test()->evaluationPaths = [...test()->evaluationPaths, $path];

    return SearchEvaluationManifest::open($path, $dataset, 'zz-search-test');
}

function evaluationMappings(SearchEvaluationDataset $dataset, SearchEvaluationManifest $manifest): void
{
    foreach ($dataset->data['sources'] as $index => $source) {
        $manifest->data['sources'][$source['key']] = ['id' => str_pad((string) ($index + 1), 13, '0', STR_PAD_LEFT), 'version' => 1, 'payload_hash' => hash('sha256', json_encode($dataset->publication($source), JSON_THROW_ON_ERROR))];
    }
}

beforeEach(function () {
    $this->evaluationPaths = [];
    Http::preventStrayRequests();
});

afterEach(function () {
    File::delete($this->evaluationPaths);
});

test('streamable client initializes and sends authenticated session and negotiated protocol headers', function () {
    Http::fake(function (Request $request) {
        $method = $request['method'];
        if ($method === 'notifications/initialized') {
            return Http::response('', 202);
        }
        $result = $method === 'initialize' ? ['protocolVersion' => '2025-11-25'] : ['structuredContent' => ['authenticated' => true, 'organization' => 'zz-search-test', 'scopes' => ['artifacts:read']]];

        return Http::response(['jsonrpc' => '2.0', 'id' => $request['id'], 'result' => $result], 200, ['MCP-Session-Id' => 'test-session']);
    });
    $client = new SearchEvaluationClient('https://staging.artfct.dev/mcp', 'test-secret');
    $client->initialize();
    $client->verifyWorkspace('zz-search-test', ['artifacts:read']);
    Http::assertSent(fn (Request $request): bool => $request['method'] === 'tools/call' && $request->hasHeader('Authorization', 'Bearer test-secret') && $request->hasHeader('MCP-Session-Id', 'test-session') && $request->hasHeader('MCP-Protocol-Version', '2025-11-25'));
    Http::assertSentCount(3);
});

test('streamable client parses matching SSE responses without treating notifications as results', function () {
    Http::fake(function (Request $request) {
        if ($request['method'] === 'notifications/initialized') {
            return Http::response('', 202);
        }
        $result = $request['method'] === 'initialize' ? ['protocolVersion' => '2025-11-25'] : ['structuredContent' => ['results' => []]];
        $payload = json_encode(['jsonrpc' => '2.0', 'id' => $request['id'], 'result' => $result]);

        return Http::response("event: message\ndata: {\"jsonrpc\":\"2.0\",\"method\":\"notifications/progress\"}\n\nevent: message\ndata: {$payload}\n\n", 200, ['Content-Type' => 'text/event-stream', 'MCP-Session-Id' => 'test-session']);
    });
    $client = new SearchEvaluationClient('https://staging.artfct.dev/mcp', 'test-secret');
    $client->initialize();
    expect($client->call('search_artifacts', ['query' => 'test']))->toBe(['results' => []]);
});

test('client refuses production, missing authentication and uninitialized tools before network access', function () {
    expect(fn () => (new SearchEvaluationClient('https://artfct.dev/mcp', 'secret'))->initialize())->toThrow(RuntimeException::class);
    expect(fn () => (new SearchEvaluationClient('https://staging.artfct.dev/mcp', ''))->initialize())->toThrow(RuntimeException::class);
    expect(fn () => (new SearchEvaluationClient('https://staging.artfct.dev/mcp', 'secret'))->call('deploy_artifact'))->toThrow(RuntimeException::class);
    Http::assertNothingSent();
});

test('client rejects HTTP, JSON RPC and missing session failures without leaking provider body', function (array|string $body, int $status, array $headers) {
    Http::fake(['*' => Http::response($body, $status, $headers)]);
    $client = new SearchEvaluationClient('https://staging.artfct.dev/mcp', 'hidden-token');
    try {
        $client->initialize();
        test()->fail('Expected protocol failure');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->not->toContain('hidden-token', 'provider-secret');
    }
})->with([
    'HTTP' => ['provider-secret', 401, []],
    'malformed' => ['provider-secret', 200, []],
    'RPC error' => [['jsonrpc' => '2.0', 'id' => 1, 'error' => ['message' => 'provider-secret']], 200, []],
    'mismatched id' => [['jsonrpc' => '2.0', 'id' => 9, 'result' => ['protocolVersion' => '2025-11-25']], 200, ['MCP-Session-Id' => 's']],
    'missing session' => [['jsonrpc' => '2.0', 'id' => 1, 'result' => ['protocolVersion' => '2025-11-25']], 200, []],
]);

test('workspace verification rejects non synthetic identity and missing scopes', function () {
    $client = Mockery::mock(SearchEvaluationClient::class)->makePartial();
    $client->shouldReceive('call')->with('get_connection')->twice()->andReturn(['authenticated' => true, 'organization' => 'other-team', 'scopes' => ['artifacts:read']]);
    expect(fn () => $client->verifyWorkspace('zz-search-test', ['artifacts:read']))->toThrow(RuntimeException::class);
    expect(fn () => $client->verifyWorkspace('zz-search-test', ['artifacts:deploy']))->toThrow(RuntimeException::class);
});

test('manifest resumes exact identity and rejects dataset drift or invalid cleanup mappings', function () {
    $dataset = evaluationDataset();
    $manifest = evaluationManifest($dataset);
    evaluationMappings($dataset, $manifest);
    $manifest->save();
    expect(SearchEvaluationManifest::open($manifest->path, $dataset, 'zz-search-test')->ids())->toBe($manifest->ids());
    expect(fn () => SearchEvaluationManifest::open($manifest->path, $dataset, 'actual-team'))->toThrow(RuntimeException::class);
    $manifest->data['sources']['landing']['id'] = '../../danger';
    $manifest->save();
    expect(fn () => SearchEvaluationManifest::open($manifest->path, $dataset, 'zz-search-test'))->toThrow(RuntimeException::class);
});

test('healthy setup resumes without republishing and reuses existing collections', function () {
    $dataset = evaluationDataset();
    $manifest = evaluationManifest($dataset);
    evaluationMappings($dataset, $manifest);
    $client = Mockery::mock(SearchEvaluationClient::class);
    $client->shouldReceive('initialize')->once();
    $client->shouldReceive('verifyWorkspace')->once()->andReturn([]);
    foreach ($dataset->data['sources'] as $source) {
        $client->shouldReceive('call')->with('get_artifact', ['id' => $manifest->ids()[$source['key']]])->once()->andReturn(['id' => $manifest->ids()[$source['key']], 'version' => 1, 'title' => $source['title'], 'sharing' => 'team']);
    }
    $client->shouldReceive('call')->with('list_collections', ['limit' => 50])->once()->andReturn(['collections' => [['name' => 'zz-search-content', 'id' => 1], ['name' => 'zz-search-operations', 'id' => 2]], 'next_cursor' => null]);
    $client->shouldReceive('call')->with('add_collection_artifact', Mockery::type('array'))->times(20)->andReturn(['added' => true]);
    (new SearchEvaluationRunner($client))->setup($dataset, $manifest);
    expect($manifest->data['sources'])->toHaveCount(20);
});

test('readiness rejects extracted but not embedded artifacts and stale extraction versions', function () {
    $dataset = evaluationDataset();
    $manifest = evaluationManifest($dataset);
    evaluationMappings($dataset, $manifest);
    $team = Team::factory()->create(['slug' => 'zz-search-test']);
    ArtifactIndexEntry::factory()->create(['team_id' => $team->id, 'artifact_id' => $manifest->ids()['landing'], 'version' => 1, 'extracted_text' => $dataset->data['sources'][0]['sections'][0]['text'].' '.$dataset->data['sources'][0]['sections'][1]['text']]);
    $readiness = new SearchEvaluationReadiness;
    $report = $readiness->inspect($dataset, $manifest);
    expect($report['ready'])->toBeFalse()->and($report['sources']['landing']['reason'])->toBe('chunk_embedding_upsert_incomplete_or_stale');
    $manifest->data['sources']['landing']['version'] = 2;
    expect($readiness->inspect($dataset, $manifest)['sources']['landing']['reason'])->toBe('stale_extraction_version');
});

test('run records all sequential tool errors but does not score a partial failure', function () {
    $dataset = evaluationDataset();
    $manifest = evaluationManifest($dataset);
    evaluationMappings($dataset, $manifest);
    $client = Mockery::mock(SearchEvaluationClient::class);
    $client->shouldReceive('initialize')->once();
    $client->shouldReceive('verifyWorkspace')->once()->andReturn([]);
    $expected = collect($dataset->data['cases'])->where('split', 'regression')->count();
    $client->shouldReceive('call')->with('search_artifacts', Mockery::on(fn (array $arguments): bool => $arguments['limit'] === 5))->times($expected)->andThrow(new RuntimeException('MCP transport HTTP 503.'));
    $output = tempnam(sys_get_temp_dir(), 'rub442-run-');
    $this->evaluationPaths[] = $output;
    $readiness = ['ready' => true, 'dataset_hash' => $dataset->hash(), 'manifest_hash' => $manifest->hash(), 'organization' => 'zz-search-test', 'checked_at' => now()->toIso8601String()];
    $run = (new SearchEvaluationRunner($client))->run($dataset, $manifest, $readiness, 'regression', 'baseline-commit', $output);
    expect($run['aggregate']['scored'])->toBeFalse()->and($run['aggregate']['tool_errors'])->toBe($expected)
        ->and(json_decode(File::get($output), true)['run_id'])->toBe($run['run_id']);
});

test('cleanup targets only manifest artifacts and keeps reusable collections', function () {
    $dataset = evaluationDataset();
    $manifest = evaluationManifest($dataset);
    evaluationMappings($dataset, $manifest);
    $manifest->data['sources'] = ['landing' => $manifest->data['sources']['landing']];
    $manifest->data['collections'] = ['zz-search-content' => 1];
    $client = Mockery::mock(SearchEvaluationClient::class);
    $client->shouldReceive('initialize')->once();
    $client->shouldReceive('verifyWorkspace')->with('zz-search-test', ['artifacts:delete'])->once()->andReturn([]);
    $client->shouldReceive('call')->with('delete_artifact', ['id' => $manifest->ids()['landing']])->once()->andReturn(['deleted' => true]);
    (new SearchEvaluationRunner($client))->cleanup($manifest);
    expect($manifest->data['sources'])->toBe([])->and($manifest->data['collections'])->toBe(['zz-search-content' => 1]);
});

test('evaluation artisan validates the frozen dataset and rejects an unknown operation', function () {
    $this->artisan('synthetic:search-evaluation', ['operation' => 'validate'])->assertSuccessful();
    $this->artisan('synthetic:search-evaluation', ['operation' => 'not-an-operation'])->assertFailed();
});

test('run refuses stale readiness and an existing held out output before calling MCP', function () {
    $dataset = evaluationDataset();
    $manifest = evaluationManifest($dataset);
    evaluationMappings($dataset, $manifest);
    $output = tempnam(sys_get_temp_dir(), 'rub442-held-');
    $this->evaluationPaths[] = $output;
    $client = Mockery::mock(SearchEvaluationClient::class);
    $readiness = ['ready' => true, 'dataset_hash' => $dataset->hash(), 'manifest_hash' => $manifest->hash(), 'organization' => 'zz-search-test', 'checked_at' => now()->subMinutes(11)->toIso8601String()];
    $runner = new SearchEvaluationRunner($client);
    expect(fn () => $runner->run($dataset, $manifest, $readiness, 'held_out', 'verified', $output))->toThrow(RuntimeException::class, 'fresh');
    $readiness['checked_at'] = now()->toIso8601String();
    expect(fn () => $runner->run($dataset, $manifest, $readiness, 'held_out', 'verified', $output))->toThrow(RuntimeException::class, 'overwrite');
});

test('readiness recognizes current completed embeddings and detects missing source evidence', function () {
    $dataset = evaluationDataset();
    $manifest = evaluationManifest($dataset);
    evaluationMappings($dataset, $manifest);
    $team = Team::factory()->create(['slug' => 'zz-search-test']);
    $text = implode(' ', array_column($dataset->data['sources'][0]['sections'], 'text'));
    $entry = ArtifactIndexEntry::factory()->create(['team_id' => $team->id, 'artifact_id' => $manifest->ids()['landing'], 'version' => 1, 'title' => null, 'extracted_text' => $text, 'extracted_at' => now()->startOfSecond()]);
    foreach (Chunker::chunk($text) as $chunk) {
        ArtifactChunk::factory()->create(['team_id' => $team->id, 'artifact_id' => $entry->artifact_id, 'text' => $chunk, 'embedding' => array_fill(0, 1024, 0.5), 'chunk_created_at' => $entry->extracted_at]);
    }
    $readiness = new SearchEvaluationReadiness;
    expect($readiness->inspect($dataset, $manifest)['sources']['landing']['ready'])->toBeTrue();
    $entry->update(['extracted_text' => 'Title only']);
    expect($readiness->inspect($dataset, $manifest)['sources']['landing']['reason'])->toBe('gold_text_missing_from_extraction');
});
