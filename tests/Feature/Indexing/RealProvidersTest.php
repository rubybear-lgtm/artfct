<?php

use App\Jobs\IndexArtifactJob;
use App\Models\ArtifactIndexingFailure;
use App\Models\Team;
use App\Services\Indexing\IndexingService;
use App\Services\Indexing\RealEmbeddings;
use App\Services\Indexing\RealRenderer;
use App\Services\Indexing\RealReranker;
use App\Services\Indexing\RendererContract;
use App\Services\Indexing\RenderTimeoutException;
use App\Services\Indexing\VectorChunk;
use App\Services\Indexing\VectorMatch;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.cloudflare.account_id' => 'test-account', 'services.cloudflare.api_token' => 'secret-provider-token']);
    configureArtifactLinks();
    Http::preventStrayRequests();
});

test('real_embeddings_asserts_expected_dimension', function (array $data) {
    Http::fake(['*' => Http::response(['success' => true, 'result' => ['data' => $data]])]);
    expect(fn () => app(RealEmbeddings::class)->embed(['document']))->toThrow(RuntimeException::class);
})->with([
    'wrong dimension' => [[[0.1, 0.2]]],
    'missing vector' => [[]],
    'extra vector' => [[array_fill(0, 1024, 0.1), array_fill(0, 1024, 0.2)]],
    'nonnumeric value' => [[array_fill(0, 1024, 'invalid')]],
]);

test('real_embeddings_batches_documents_and_instructs_only_queries', function () {
    Http::fake(fn ($request) => Http::response(['success' => true, 'result' => [
        'data' => array_fill(0, count($request['text']), array_fill(0, 1024, 0.1)),
    ]]));
    $embeddings = app(RealEmbeddings::class);
    expect($embeddings->embed(array_fill(0, 65, 'document')))->toHaveCount(65);
    expect($embeddings->embedQuery('query'))->toHaveCount(1024);
    Http::assertSentCount(4);
    $requests = Http::recorded()->map(fn ($pair) => $pair[0]->data())->all();
    expect(array_map(fn ($body) => count($body['text']), $requests))->toBe([32, 32, 1, 1]);
    expect($requests[0])->not->toHaveKey('instruction');
    expect($requests[3]['instruction'])->toBe('Given a web search query, retrieve relevant passages that answer the query');
});

test('real_renderer_opens_signed_origin_and_extracts_visible_text', function () {
    Http::fake(['*' => Http::response(['success' => true, 'result' => [
        ['selector' => 'body', 'results' => [['text' => 'Hydrated revenue dashboard']]],
        ['selector' => 'title', 'results' => [['text' => 'Revenue']]],
        ['selector' => 'h1, h2, h3, h4, h5, h6, th', 'results' => [
            ['text' => 'Visible heading', 'height' => 20, 'width' => 100],
            ['text' => 'Hidden heading', 'height' => 0, 'width' => 0],
        ]],
        ['selector' => '[aria-label]', 'results' => [
            ['height' => 20, 'width' => 100, 'attributes' => [['name' => 'aria-label', 'value' => 'Revenue grew 40 percent']]],
        ]],
    ]])]);
    $result = app(RealRenderer::class)->render('acme', ARTIFACT_LINK_ID, '<div id="root"></div>');
    expect($result->text)->toContain('Hydrated revenue dashboard', 'Revenue grew 40 percent');
    expect($result->title)->toBe('Revenue');
    expect($result->headings)->toBe(['Visible heading']);
    Http::assertSent(function ($request) {
        $url = $request['url'];
        parse_str(parse_url($url, PHP_URL_QUERY), $params);

        return parse_url($url, PHP_URL_HOST) === 'acme--'.ARTIFACT_LINK_ID.'.artfct.dev'
            && artifactTokenVerifies($params['token'], ARTIFACT_LINK_ID, ARTIFACT_LINK_SECRET, now()->timestamp)
            && $request['allowRequestPattern'] === ['^https://acme--'.ARTIFACT_LINK_ID.'\.artfct\.dev(?:[/?#]|$)']
            && ! isset($request['cookies'])
            && ! isset($request['html'])
            && $request['gotoOptions']['timeout'] === 15000;
    });

    $requestPattern = Http::recorded()->first()[0]['allowRequestPattern'][0];
    expect(preg_match('~'.$requestPattern.'~', 'https://acme--'.ARTIFACT_LINK_ID.'.artfct.dev/page?token=opaque'))->toBe(1);
    foreach ([
        'http://acme--'.ARTIFACT_LINK_ID.'.artfct.dev/page',
        'https://acme--'.ARTIFACT_LINK_ID.'.artfct.dev.evil.test/',
        'https://127.0.0.1/',
        'https://2130706433/',
        'https://[::1]/',
        'https://[fd00::1]/',
        'https://169.254.169.254/',
        'https://10.0.0.1/',
        'https://192.168.1.1/',
        'https://localhost/',
    ] as $disallowedUrl) {
        expect(preg_match('~'.$requestPattern.'~', $disallowedUrl))->toBe(0);
    }
});

test('real_renderer_timeout_raises_for_retry', function (string $failure) {
    Http::fake(['*' => $failure === 'connection'
        ? Http::failedConnection()
        : Http::response(['success' => false, 'errors' => [['message' => 'Navigation timeout '.$failure.' secret-provider-token']]], 504)]);
    app()->instance(RendererContract::class, app(RealRenderer::class));
    $team = Team::factory()->create(['slug' => 'timeout-org']);
    $job = new IndexArtifactJob($team->id, ARTIFACT_LINK_ID, '<div id="root"></div>', []);
    try {
        $job->handle(app(IndexingService::class));
        test()->fail('Render should time out.');
    } catch (RenderTimeoutException $exception) {
        $job->failed($exception);
    }
    $failure = ArtifactIndexingFailure::query()->firstOrFail();
    expect($failure->reason)->not->toContain('secret-provider-token', '?token=');
    expect($job->tries)->toBe(3);
    expect($job->backoff())->toBe([10, 30, 60]);
})->with(['connection', 'navigation']);

test('provider_errors_do_not_persist_tokens_or_artifact_text', function () {
    Http::fake(['*' => Http::response(['success' => false, 'errors' => [['message' => 'secret-provider-token private-artifact-text']]], 403)]);
    try {
        app(RealEmbeddings::class)->embed(['private-artifact-text']);
        test()->fail('Provider should fail.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Cloudflare indexing request failed (HTTP 403).');
        expect($exception->getPrevious())->toBeNull();
    }
});

function rerankerCandidates(): array
{
    return array_map(fn (string $text): VectorMatch => new VectorMatch(
        new VectorChunk($text, [1.0], $text, 'acme', now()->toIso8601String(), null, null, null), 0.5,
    ), ['first', 'second']);
}

test('real_reranker_sorts_scores_by_input_id', function () {
    Http::fake(['*' => Http::response(['success' => true, 'result' => ['response' => [
        ['id' => 0, 'score' => 0.1], ['id' => 1, 'score' => 0.9],
    ]]])]);
    $results = app(RealReranker::class)->rerank('query', rerankerCandidates());
    expect($results[0]->chunk->artifactId)->toBe('second');
    expect($results[0]->similarity)->toBe(0.9);
    Http::assertSent(fn ($request) => $request['contexts'] === [['text' => 'first'], ['text' => 'second']]);
});

test('real_reranker_rejects_malformed_candidate_mapping', function (array $scores) {
    Http::fake(['*' => Http::response(['success' => true, 'result' => ['response' => $scores]])]);
    expect(fn () => app(RealReranker::class)->rerank('query', rerankerCandidates()))->toThrow(RuntimeException::class);
})->with([
    'missing score' => [[['id' => 0, 'score' => 0.1]]],
    'duplicate id' => [[['id' => 0, 'score' => 0.1], ['id' => 0, 'score' => 0.2]]],
    'unknown id' => [[['id' => 0, 'score' => 0.1], ['id' => 7, 'score' => 0.2]]],
    'invalid score' => [[['id' => 0, 'score' => 0.1], ['id' => 1, 'score' => 2.0]]],
]);

test('empty_provider_batches_do_not_make_requests', function () {
    expect(app(RealEmbeddings::class)->embed([]))->toBe([]);
    expect(app(RealReranker::class)->rerank('query', []))->toBe([]);
    Http::assertNothingSent();
});
