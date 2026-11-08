<?php

/**
 * RUB-516 items 3–4: search must return scores that match result order, keep
 * exact-title matches clearly ahead, preserve heading words in snippets, and
 * not hang for ~10s on no-results when Workers AI is slow.
 */

use App\Contracts\ArtifactDirectory;
use App\Models\Team;
use App\Services\Indexing\EmbeddingsContract;
use App\Services\Indexing\RerankerContract;
use App\Services\Indexing\VectorChunk;
use App\Services\Indexing\VectorIndexContract;
use App\Services\Indexing\VectorMatch;
use App\Services\Search\SearchRanking;
use App\Services\Search\SearchService;
use App\Services\Search\SearchSnippet;

function rub516Match(string $id, string $text, float $score, string $org = 'rub516-search'): VectorMatch
{
    return new VectorMatch(
        new VectorChunk($text, [1.0], $id, $org, now()->toIso8601String(), null, null, null),
        $score,
    );
}

test('rub516_item3_returned_scores_are_sorted_descending_and_exact_title_leads_by_gap', function () {
    // Unequal similarities reproduce production: exact title mid-similarity
    // ranked first by title_tier while a semantic miss scored higher on the
    // returned displayScore (0.726 then 0.878).
    $titles = [
        'exact' => 'Design partner prospects',
        'near' => 'Artfct launch plan',
        'billing' => 'Billing dashboard notes',
        'onboarding' => 'Vendor onboarding matrix',
        'recruiting' => 'Recruiting connector rollout',
    ];
    $matches = [
        rub516Match('near', 'Go-to-market launch plan body about partners.', 0.978),
        rub516Match('exact', 'Design partner prospects table and outreach notes.', 0.807),
        rub516Match('billing', 'Billing dashboard notes about invoices.', 0.6),
        rub516Match('onboarding', 'Vendor onboarding matrix checklist.', 0.55),
        rub516Match('recruiting', 'Recruiting connector rollout stages.', 0.5),
    ];

    $ranked = SearchRanking::rank(
        $matches,
        'Design partner prospects',
        [],
        [],
        $titles,
    );
    expect($ranked)->toHaveCount(5)
        ->and($ranked[0]->chunk->artifactId)->toBe('exact');

    // Title-match bands put exact ahead of near/miss by construction; SearchService
    // additionally aligns scores when boosts (e.g. repo) disagree with raw similarity.
    $aligned = SearchRanking::alignScoresWithOrder(array_map(
        fn (VectorMatch $match): float => SearchRanking::displayScore(
            $match->similarity,
            'Design partner prospects',
            $titles[$match->chunk->artifactId],
        ),
        $ranked,
    ));

    for ($i = 0; $i < count($aligned) - 1; $i++) {
        expect($aligned[$i])->toBeGreaterThanOrEqual($aligned[$i + 1]);
    }
    expect($aligned[0] - $aligned[1])->toBeGreaterThanOrEqual(0.05);
});

test('rub516_item3_search_service_orders_by_returned_score_with_production_path', function () {
    $team = Team::factory()->create(['slug' => 'rub516-search']);
    $directory = app(ArtifactDirectory::class);
    $index = app(VectorIndexContract::class);

    $artifacts = [
        'exact' => [
            'title' => 'Design partner prospects',
            'text' => 'Design partner prospects. Outreach notes for design partners and trial accounts across the first cohort.',
            'similarity' => 0.807,
        ],
        'near' => [
            'title' => 'Artfct launch plan',
            'text' => 'Artfct launch plan. Go-to-market timeline and messaging for the public release about partners.',
            'similarity' => 0.978,
        ],
        'billing' => [
            'title' => 'Billing dashboard',
            'text' => 'Billing dashboard. Monthly invoice reconciliation and failed payment retries.',
            'similarity' => 0.55,
        ],
        'retention' => [
            'title' => 'Retention review',
            'text' => 'Retention review. Cohort retention curves and churn drivers.',
            'similarity' => 0.5,
        ],
        'onboarding' => [
            'title' => 'Onboarding checklist',
            'text' => 'Onboarding checklist. First-week setup steps and MCP connection verification.',
            'similarity' => 0.45,
        ],
    ];

    foreach ($artifacts as $id => $meta) {
        $directory->seedArtifact([
            'id' => $id,
            'org_id' => $team->slug,
            'user_id' => 1,
            'title' => $meta['title'],
            'created_at' => now()->toIso8601String(),
            'revoked_at' => null,
            'provenance' => [],
        ]);
        $index->upsertChunks($team->slug, $id, [
            rub516Match($id, $meta['text'], $meta['similarity'], $team->slug)->chunk,
        ]);
    }

    $similarities = array_column($artifacts, 'similarity', null);
    $idByTitle = [];
    foreach ($artifacts as $id => $meta) {
        $idByTitle[$meta['title']] = $id;
    }

    $reranker = Mockery::mock(RerankerContract::class);
    $reranker->shouldReceive('rerank')->andReturnUsing(function (string $query, array $passages) use ($artifacts): array {
        return array_map(
            function (VectorMatch $match) use ($artifacts): VectorMatch {
                $similarity = $artifacts[$match->chunk->artifactId]['similarity'] ?? $match->similarity;

                return new VectorMatch($match->chunk, $similarity);
            },
            $passages,
        );
    });
    app()->instance(RerankerContract::class, $reranker);

    $results = app(SearchService::class)->search($team, 'Design partner prospects', [], 5, 'tester');

    expect($results)->toHaveCount(5)
        ->and($results[0]->id)->toBe('exact')
        ->and($results[0]->score - $results[1]->score)->toBeGreaterThanOrEqual(0.05);

    for ($i = 0; $i < count($results) - 1; $i++) {
        expect($results[$i]->score)->toBeGreaterThanOrEqual($results[$i + 1]->score);
    }
});

test('rub516_item3_snippets_keep_heading_words_when_title_is_only_a_prefix', function () {
    $title = 'hoku-test-md';
    $text = 'hoku-test-md Title Table section with rows and cells for the verification case.';

    $snippet = SearchSnippet::make($text, $title, $title, 200);

    expect($snippet)->toContain('hoku-test-md')
        ->and($snippet)->toContain('Title')
        ->and($snippet)->not->toStartWith('Title Table');
});

test('rub516_item4_no_results_search_finishes_under_1_5s_when_embeddings_hang', function () {
    $team = Team::factory()->create(['slug' => 'rub516-slow-embed']);
    $directory = app(ArtifactDirectory::class);

    // Production-like: artifacts exist in the directory, but nothing is indexed
    // for this nonsense query — the path that still called Workers AI and hung.
    for ($i = 1; $i <= 5; $i++) {
        $directory->seedArtifact([
            'id' => 'art'.$i,
            'org_id' => $team->slug,
            'user_id' => 1,
            'title' => "Artifact {$i}",
            'created_at' => now()->toIso8601String(),
            'revoked_at' => null,
            'provenance' => [],
        ]);
    }

    // Simulate the production failure mode: Workers AI never returns in time.
    // SearchService must fall back to lexical-only instead of waiting ~10s.
    $embeddings = new class implements EmbeddingsContract
    {
        public int $calls = 0;

        public function embed(array $texts): array
        {
            $this->calls++;
            throw new RuntimeException('Workers AI request timed out or could not connect.');
        }

        public function embedQuery(string $query): array
        {
            return $this->embed([$query])[0];
        }
    };
    app()->instance(EmbeddingsContract::class, $embeddings);

    $started = hrtime(true);
    $results = app()->make(SearchService::class, ['embeddings' => $embeddings])->search(
        $team,
        'zzzxxyyqqq-no-such-artifact-ever-rub516',
        [],
        5,
        'tester',
    );
    $elapsedMs = (hrtime(true) - $started) / 1_000_000;

    expect($results)->toBe([])
        ->and($embeddings->calls)->toBe(1)
        ->and($elapsedMs)->toBeLessThan(1500);
});

test('rub516_item4_empty_allowed_set_skips_embeddings_entirely', function () {
    $team = Team::factory()->create(['slug' => 'rub516-empty-dir']);
    $embeddings = new class implements EmbeddingsContract
    {
        public int $calls = 0;

        public function embed(array $texts): array
        {
            $this->calls++;

            return array_map(fn () => array_fill(0, 8, 0.1), $texts);
        }

        public function embedQuery(string $query): array
        {
            return $this->embed([$query])[0];
        }
    };

    // FakeArtifactDirectory serves seeded rows to every org slug, so force an
    // empty allowed set with a future `since` filter instead of relying on an
    // empty directory.
    $started = hrtime(true);
    $results = app()->make(SearchService::class, ['embeddings' => $embeddings])->search(
        $team,
        'anything',
        ['since' => now()->addDay()->toIso8601String()],
        5,
        'tester',
    );
    $elapsedMs = (hrtime(true) - $started) / 1_000_000;

    expect($results)->toBe([])
        ->and($embeddings->calls)->toBe(0)
        ->and($elapsedMs)->toBeLessThan(1500);
});
