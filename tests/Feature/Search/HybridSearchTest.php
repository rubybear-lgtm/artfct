<?php

use App\Contracts\ArtifactDirectory;
use App\Models\Team;
use App\Services\Indexing\RerankerContract;
use App\Services\Indexing\VectorChunk;
use App\Services\Indexing\VectorIndexContract;
use App\Services\Indexing\VectorMatch;
use App\Services\Search\ReciprocalRankFusion;
use App\Services\Search\SearchService;

function hybridMatch(string $id, string $org = 'hybrid-org', string $text = 'search content'): VectorMatch
{
    return new VectorMatch(new VectorChunk($text, [1.0], $id, $org, now()->toIso8601String(), null, null, null), 0.9);
}

test('rank_fusion_combines_signals_and_deduplicates_artifacts', function () {
    $semanticOnly = hybridMatch('semantic-only');
    $lexicalOnly = hybridMatch('lexical-only');
    $both = hybridMatch('both');
    $fused = ReciprocalRankFusion::combine([$semanticOnly, $both, $both], [$lexicalOnly, $both]);
    expect(array_map(fn ($match) => $match->chunk->artifactId, $fused))->toBe(['both', 'semantic-only', 'lexical-only']);
    expect($fused[0]->similarity)->toBe(2 / 62);
});

test('hybrid_search_reranks_only_accessible_candidates_in_the_calling_org', function () {
    $team = Team::factory()->create(['slug' => 'hybrid-org']);
    $directory = app(ArtifactDirectory::class);
    foreach (['semantic', 'lexical', 'revoked'] as $id) {
        $directory->seedArtifact([
            'id' => $id, 'org_id' => $team->slug, 'user_id' => 1, 'title' => $id,
            'created_at' => now()->toIso8601String(), 'revoked_at' => $id === 'revoked' ? now()->toIso8601String() : null,
            'provenance' => [],
        ]);
    }
    $index = Mockery::mock(VectorIndexContract::class);
    $index->shouldReceive('query')->once()->with($team->slug, Mockery::type('array'), 50)->andReturn([
        hybridMatch('semantic'), hybridMatch('revoked'), hybridMatch('foreign', 'other-org'), hybridMatch('deleted'),
    ]);
    $index->shouldReceive('queryText')->once()->with($team->slug, 'identifier', 50)->andReturn([hybridMatch('lexical')]);
    app()->instance(VectorIndexContract::class, $index);
    $reranker = Mockery::mock(RerankerContract::class);
    $reranker->shouldReceive('rerank')->once()->with('identifier', Mockery::on(function ($candidates) {
        $ids = array_map(fn ($match) => $match->chunk->artifactId, $candidates);
        sort($ids);

        return $ids === ['lexical', 'semantic'];
    }))->andReturn([new VectorMatch(hybridMatch('lexical')->chunk, 0.9), new VectorMatch(hybridMatch('semantic')->chunk, 0.1)]);
    app()->instance(RerankerContract::class, $reranker);
    $results = app(SearchService::class)->search($team, 'identifier', [], 5, 'tester');
    expect(array_map(fn ($result) => $result->id, $results))->toBe(['lexical', 'semantic']);
});
