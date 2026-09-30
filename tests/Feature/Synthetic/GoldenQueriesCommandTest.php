<?php

use App\Contracts\ArtifactDirectory;
use App\Models\Team;
use App\Services\Artifacts\FakeArtifactDirectory;
use App\Services\Indexing\EmbeddingsContract;
use App\Services\Indexing\FakeVectorIndex;
use App\Services\Indexing\VectorChunk;
use App\Services\Indexing\VectorIndexContract;
use App\Services\Synthetic\SyntheticSeeder;
use Illuminate\Support\Facades\File;

/**
 * Seeds two indexed artifacts for a synthetic org, writes the seed's key map,
 * and returns the path of a one-off fixture with the given queries.
 *
 * @param  list<array{query: string, expected_keys: list<string>}>  $queries
 */
function goldenQueriesFixture(Team $team, array $queries): string
{
    /** @var FakeArtifactDirectory $directory */
    $directory = app(ArtifactDirectory::class);
    /** @var FakeVectorIndex $index */
    $index = app(VectorIndexContract::class);
    $texts = ['billing-dash' => 'billing dashboard revenue overview', 'runbook-a' => 'on call handoff runbook steps'];
    $ids = [];

    foreach ($texts as $key => $text) {
        $ids[$key] = 'id-'.$key;
        $directory->seedArtifact([
            'id' => $ids[$key], 'org_id' => $team->slug, 'user_id' => 1, 'title' => "Artifact {$key}", 'description' => 'd',
            'content_hash' => md5($key), 'created_at' => now()->subDay()->toIso8601String(), 'revoked_at' => null,
            'provenance' => ['agent' => 'cursor', 'repo_url' => null, 'commit_sha' => null],
        ]);
        $index->upsertChunks($team->slug, $ids[$key], [new VectorChunk(
            text: $text, vector: app(EmbeddingsContract::class)->embed([$text])[0], artifactId: $ids[$key], orgId: $team->slug,
            createdAt: now()->subDay()->toIso8601String(), agent: 'cursor', repoUrl: null, commitSha: null,
        )]);
    }

    $mapPath = SyntheticSeeder::mapPath($team->slug);
    File::ensureDirectoryExists(dirname($mapPath));
    File::put($mapPath, json_encode($ids));

    $fixture = tempnam(sys_get_temp_dir(), 'golden');
    File::put($fixture, json_encode(['queries' => $queries]));

    return $fixture;
}

afterEach(function () {
    File::delete(SyntheticSeeder::mapPath('zz-golden'));
});

test('golden_queries_pass_when_every_expected_artifact_is_in_the_top_results', function () {
    config(['indexing.enabled' => true]);
    $team = Team::factory()->create(['slug' => 'zz-golden']);
    $fixture = goldenQueriesFixture($team, [
        ['query' => 'billing dashboard revenue overview', 'expected_keys' => ['billing-dash']],
        ['query' => 'on call handoff runbook steps', 'expected_keys' => ['runbook-a']],
    ]);

    test()->artisan('synthetic:golden-queries', ['org' => 'zz-golden', '--fixture' => $fixture])
        ->expectsOutputToContain('2 of 2 golden queries')
        ->assertSuccessful();
});

test('golden_queries_fail_and_name_the_missing_key_when_an_expected_artifact_is_absent', function () {
    config(['indexing.enabled' => true]);
    $team = Team::factory()->create(['slug' => 'zz-golden']);
    $fixture = goldenQueriesFixture($team, [
        ['query' => 'billing dashboard revenue overview', 'expected_keys' => ['billing-dash', 'never-seeded']],
    ]);

    test()->artisan('synthetic:golden-queries', ['org' => 'zz-golden', '--fixture' => $fixture])
        ->expectsOutputToContain('0 of 1 golden queries')
        ->assertFailed();
});

test('golden_queries_refuse_a_non_synthetic_org', function () {
    Team::factory()->create(['slug' => 'acme']);

    test()->artisan('synthetic:golden-queries', ['org' => 'acme'])->assertFailed();
});
