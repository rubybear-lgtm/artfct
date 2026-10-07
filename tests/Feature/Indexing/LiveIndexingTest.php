<?php

use App\Enums\TeamRole;
use App\Models\ArtifactIndexEntry;
use App\Models\Team;
use App\Services\Artifacts\ArtifactAccessLink;
use App\Services\Auth\OrgJwtService;
use App\Services\Indexing\ExtractionHeuristics;
use App\Services\Indexing\IndexingService;
use App\Services\Indexing\PgVectorIndex;
use App\Services\Indexing\RealEmbeddings;
use App\Services\Indexing\RealRenderer;
use App\Services\Indexing\VectorChunk;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    if (DB::getDriverName() !== 'pgsql'
        || ! config('services.cloudflare.api_token')
        || ! config('services.artifact_access.token_secret')
        || ! env('LIVE_ORG_A')) {
        test()->markTestSkipped('Requires pgvector, staging Cloudflare credentials and LIVE_ORG_A fixture.');
    }
});

test('live_js_heavy_artifact_indexes_post_hydration_text', function () {
    $org = env('LIVE_ORG_A');
    $javascriptPath = env('LIVE_DASHBOARD_JS_FILE');
    if (! $javascriptPath || ! is_readable($javascriptPath)) {
        test()->markTestSkipped('Build tests/Fixtures/indexing-dashboard.tsx and set LIVE_DASHBOARD_JS_FILE.');
    }
    $expected = 'Hydrated React revenue dashboard';
    $javascript = file_get_contents($javascriptPath);
    $html = '<!doctype html><html><head><title>React dashboard '.bin2hex(random_bytes(4)).'</title></head><body><div id="root"></div><script src="app.js"></script></body></html>';
    $files = [
        ['path' => 'index.html', 'content_type' => 'text/html; charset=utf-8', 'body' => $html],
        ['path' => 'app.js', 'content_type' => 'application/javascript', 'body' => $javascript],
    ];
    $manifestFiles = array_map(fn ($file) => [
        'path' => $file['path'], 'content_type' => $file['content_type'],
        'size_bytes' => strlen($file['body']), 'sha256' => hash('sha256', $file['body']),
    ], $files);
    $token = OrgJwtService::default()->mintFor($org, 'system', TeamRole::Admin)['token'];
    $worker = rtrim(config('services.worker.base_url'), '/');
    $created = Http::withToken($token)->timeout(20)->post($worker.'/v1/artifacts', [
        'mode' => 'permanent', 'tier' => 'secure', 'title' => 'React indexing dashboard',
        'description' => 'Live post-hydration indexing fixture',
        'thumbnail' => 'https://artfct.dev/og-image.svg', 'preview_blurred' => false,
        'manifest' => ['entrypoint' => 'index.html', 'files' => $manifestFiles, 'external_origins' => []],
        'provenance' => ['agent' => 'pest-live-indexing'],
    ])->throw();
    $id = $created->json('id');
    expect($id)->toMatch('/\A[a-z0-9]{13}\z/');
    foreach ($files as $file) {
        $sha256 = hash('sha256', $file['body']);
        if (in_array($sha256, $created->json('missing_files', []), true)) {
            Http::withToken($token)->withBody($file['body'], $file['content_type'])
                ->timeout(20)->put($worker."/v1/artifacts/{$id}/files/{$sha256}")->throw();
        }
    }
    $url = ArtifactAccessLink::default()->forArtifact($org, $id);
    $entryResponse = Http::timeout(15)->get($url)->throw();
    $source = $entryResponse->body();
    expect($entryResponse->header('Set-Cookie'))->toContain('artfct_access=');
    $host = parse_url($url, PHP_URL_HOST);
    parse_str(parse_url($url, PHP_URL_QUERY), $params);
    $scriptResponse = Http::withCookies(['artfct_access' => $params['token']], $host)
        ->timeout(15)->get("https://{$host}/p/{$id}/app.js");
    expect($scriptResponse->status())->toBe(200);
    expect($scriptResponse->header('Content-Type'))->toContain('javascript');
    expect(hash('sha256', $scriptResponse->body()))->toBe(hash('sha256', $javascript));
    expect($entryResponse->header('Content-Security-Policy'))->toContain("script-src 'self'");
    expect(ExtractionHeuristics::needsRender($source))->toBeTrue();
    expect($source)->not->toContain($expected);
    $content = Http::withToken(config('services.cloudflare.api_token'))->timeout(30)
        ->post('https://api.cloudflare.com/client/v4/accounts/'.config('services.cloudflare.account_id').'/browser-run/content', [
            'url' => $url, 'gotoOptions' => ['waitUntil' => 'networkidle0', 'timeout' => 15000],
        ])->throw();
    expect($content->json('success'))->toBeTrue();
    expect($content->json('result'))->toContain($expected);
    $team = Team::factory()->create(['slug' => $org]);
    $index = app(PgVectorIndex::class);
    $service = new IndexingService(app(RealRenderer::class), app(RealEmbeddings::class), $index);
    $entry = $service->indexArtifact($team, $id, $source, []);
    expect($entry->rendered)->toBeTrue();
    expect(ArtifactIndexEntry::query()->whereKey($entry->id)->value('extracted_text'))->toContain($expected);
    $matches = $index->query($org, app(RealEmbeddings::class)->embedQuery($expected), 10);
    expect(array_column(array_map(fn ($match) => $match->chunk, $matches), 'artifactId'))->toContain($id);
})->group('live');

test('live_two_orgs_never_see_each_other_vectors', function () {
    $orgA = env('LIVE_ORG_A');
    $orgB = env('LIVE_ORG_B');
    if (! $orgB) {
        test()->markTestSkipped('LIVE_ORG_B is required.');
    }
    Team::factory()->create(['slug' => $orgA]);
    Team::factory()->create(['slug' => $orgB]);
    $idA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    $idB = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    $text = 'Overlapping live provider tenant isolation content';
    $vector = app(RealEmbeddings::class)->embed([$text])[0];
    $index = app(PgVectorIndex::class);
    foreach ([[$orgA, $idA], [$orgB, $idB]] as [$org, $id]) {
        $index->upsertChunks($org, $id, [new VectorChunk($text, $vector, $id, $org, now()->toIso8601String(), null, null, null)]);
    }
    $queryVector = app(RealEmbeddings::class)->embedQuery($text);
    expect(array_map(fn ($chunk) => $chunk->artifactId, $index->allVectorsForOrg($orgA)))->toBe([$idA]);
    expect(array_map(fn ($match) => $match->chunk->artifactId, $index->query($orgA, $queryVector, 10)))->toBe([$idA]);
    expect(array_map(fn ($match) => $match->chunk->artifactId, $index->queryText($orgA, 'Overlapping', 10)))->toBe([$idA]);
})->group('live');

test('live_delete_removes_vectors', function () {
    $org = env('LIVE_ORG_A');
    Team::factory()->create(['slug' => $org]);
    $id = 'cccccccccccccccccccccccccccccccc';
    $text = 'Live provider deletion verification';
    $vector = app(RealEmbeddings::class)->embed([$text])[0];
    $index = app(PgVectorIndex::class);
    $index->upsertChunks($org, $id, [
        new VectorChunk($text, $vector, $id, $org, now()->toIso8601String(), null, null, null),
        new VectorChunk('Second chunk '.$text, $vector, $id, $org, now()->toIso8601String(), null, null, null),
    ]);
    expect($index->allVectorsForOrg($org))->toHaveCount(2);
    $index->deleteArtifactVectors($org, $id);
    expect($index->allVectorsForOrg($org))->toBe([]);
    expect($index->query($org, $vector, 10))->toBe([]);
})->group('live');
