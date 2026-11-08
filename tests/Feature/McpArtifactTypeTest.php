<?php

use App\Contracts\ArtifactDirectory;
use App\Enums\ArtifactType;
use App\Models\ArtifactTypeAssignment;
use App\Models\Team;
use App\Services\Artifacts\ArtifactTypeResolver;
use App\Services\Artifacts\FakeArtifactDirectory;
use App\Services\Billing\FakeUsage;
use App\Services\Billing\UsageContract;
use App\Services\Indexing\EmbeddingsContract;
use App\Services\Indexing\FakeVectorIndex;
use App\Services\Indexing\VectorChunk;
use App\Services\Indexing\VectorIndexContract;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/*
 * RUB-443 item 3 and the changed-since signal of item 4: an artifact has a
 * type, declared by the publisher or inferred, that tools return and filter
 * on, and search results carry the version and update time a caller needs to
 * notice a republished source.
 */

const TYPED_ARTIFACT_ID = 'typed0000001a';

/** One call through the hosted MCP server. */
function callTypedTool(string $token, string $tool, array $arguments): TestResponse
{
    return test()->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => $tool, 'arguments' => $arguments],
    ]);
}

test('the resolver infers a type from the content', function (string $html, ArtifactType $expected) {
    expect((new ArtifactTypeResolver)->infer($html))->toBe($expected);
})->with([
    'table' => ['<h1>Costs</h1><table><tr><td>1</td></tr></table>', ArtifactType::Table],
    'slides' => ['<div class="slide"><h1>One</h1></div><div class="slide"><h1>Two</h1></div>', ArtifactType::SlideDeck],
    'dashboard' => ['<h1>Sales dashboard</h1><canvas></canvas>', ArtifactType::Dashboard],
    'report' => ['<h1>A</h1><h2>B</h2><h2>C</h2><p>text</p>', ArtifactType::Report],
    'document' => ['<h1>Notes</h1><p>text</p>', ArtifactType::Document],
    'other' => ['<p>hello</p>', ArtifactType::Other],
]);

test('the resolver lets a declared type win and keeps it on later versions', function () {
    $team = Team::factory()->create();
    $resolver = new ArtifactTypeResolver;

    $resolver->record($team, TYPED_ARTIFACT_ID, '<p>hello</p>', ArtifactType::Mockup);
    $kept = $resolver->record($team, TYPED_ARTIFACT_ID, '<h1>A</h1><h2>B</h2><h2>C</h2>', null);

    expect($kept)->toBe(ArtifactType::Mockup)
        ->and(ArtifactTypeAssignment::query()->where('team_id', $team->id)->count())->toBe(1);
});

test('deploy_artifact stores and returns the declared or inferred type', function () {
    configureArtifactLinks();
    $team = Team::factory()->create(['slug' => 'rub-443-type-deploy']);
    $token = remoteMcpToken($team);
    config(['services.worker.base_url' => 'https://worker.test']);
    app()->bind(UsageContract::class, FakeUsage::class);
    Http::fake(['worker.test/v1/artifacts' => Http::response([
        'id' => TYPED_ARTIFACT_ID, 'version' => 1, 'url' => 'https://worker.test/p/'.TYPED_ARTIFACT_ID.'/',
        'created' => true, 'missing_files' => [], 'tier' => 'secure', 'sharing' => 'team',
    ], 201)]);

    $inferred = callTypedTool($token, 'deploy_artifact', ['html' => '<h1>Costs</h1><table><tr><td>1</td></tr></table>'])->assertOk();
    expect($inferred->json('result.structuredContent.artifact_type'))->toBe('table');

    $declared = callTypedTool($token, 'deploy_artifact', ['html' => '<p>x</p>', 'artifact_type' => 'mockup'])->assertOk();
    expect($declared->json('result.structuredContent.artifact_type'))->toBe('mockup');

    callTypedTool($token, 'deploy_artifact', ['html' => '<p>x</p>', 'artifact_type' => 'spreadsheet'])
        ->assertOk()
        ->assertJsonPath('result.isError', true);
});

test('list_artifacts filters by type and returns it', function () {
    $team = Team::factory()->create(['slug' => 'rub-443-type-list']);
    $token = remoteMcpToken($team);
    config(['services.worker.base_url' => 'https://worker.test']);
    ArtifactTypeAssignment::factory()->create(['team_id' => $team->id, 'artifact_id' => 'aaaaaaaaaaaaa', 'artifact_type' => ArtifactType::Report]);
    Http::fake(['worker.test/v1/orgs/*' => Http::response(['artifacts' => [[
        'id' => 'aaaaaaaaaaaaa', 'title' => 'Q3', 'description' => 'd', 'created_at' => '2026-10-01T00:00:00Z', 'updated_at' => '2026-10-02T00:00:00Z',
        'version' => 1, 'kind' => 'html', 'sharing' => 'team', 'owner_user_id' => 'u', 'can_edit' => false, 'provenance' => [], 'revoked_at' => null,
    ]], 'next_cursor' => null])]);

    $response = callTypedTool($token, 'list_artifacts', ['artifact_type' => 'report'])->assertOk();

    expect($response->json('result.structuredContent.artifacts.0.artifact_type'))->toBe('report');
    Http::assertSent(function (Request $request): bool {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return ($query['ids'] ?? null) === 'aaaaaaaaaaaaa';
    });

    $none = callTypedTool($token, 'list_artifacts', ['artifact_type' => 'dashboard'])->assertOk();
    expect($none->json('result.structuredContent.artifacts'))->toBe([]);
});

test('search_artifacts filters by type and changed_since and returns version and update time', function () {
    config(['indexing.enabled' => true, 'app.public_base_url' => 'https://artfct.dev']);
    $team = Team::factory()->create(['slug' => 'rub-443-type-search']);
    $token = remoteMcpToken($team);

    /** @var FakeArtifactDirectory $directory */
    $directory = app(ArtifactDirectory::class);
    /** @var FakeVectorIndex $index */
    $index = app(VectorIndexContract::class);
    foreach (['old-report' => ['report', '2026-09-01T00:00:00+00:00'], 'new-table' => ['table', '2026-10-10T00:00:00+00:00']] as $id => [$type, $updated]) {
        $directory->seedArtifact([
            'id' => $id, 'org_id' => $team->slug, 'user_id' => 1, 'title' => "Artifact {$id}", 'description' => 'd',
            'content_hash' => md5($id), 'created_at' => '2026-08-01T00:00:00+00:00', 'updated_at' => $updated, 'version' => 2,
            'revoked_at' => null, 'tier' => 'secure', 'provenance' => ['agent' => 'cursor', 'repo_url' => null, 'commit_sha' => null],
        ]);
        $text = 'quarterly revenue overview '.$id;
        $index->upsertChunks($team->slug, $id, [new VectorChunk(
            text: $text, vector: app(EmbeddingsContract::class)->embed([$text])[0], artifactId: $id, orgId: $team->slug,
            createdAt: '2026-08-01T00:00:00+00:00', agent: 'cursor', repoUrl: null, commitSha: null,
        )]);
        ArtifactTypeAssignment::factory()->create(['team_id' => $team->id, 'artifact_id' => $id, 'artifact_type' => ArtifactType::from($type)]);
    }

    $byType = callTypedTool($token, 'search_artifacts', ['query' => 'quarterly revenue overview', 'artifact_type' => 'table'])->assertOk()->json('result.structuredContent.results');
    expect(array_column($byType, 'id'))->toBe(['new-table'])
        ->and($byType[0]['artifact_type'])->toBe('table')
        ->and($byType[0]['version'])->toBe(2)
        ->and($byType[0]['updated_at'])->toBe('2026-10-10T00:00:00Z');

    $changed = callTypedTool($token, 'search_artifacts', ['query' => 'quarterly revenue overview', 'changed_since' => '2026-10-01T00:00:00Z'])->assertOk()->json('result.structuredContent.results');
    expect(array_column($changed, 'id'))->toBe(['new-table']);
});
