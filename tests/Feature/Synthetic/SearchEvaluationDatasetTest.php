<?php

use App\Services\Indexing\ExtractionHeuristics;
use App\Services\Synthetic\SearchEvaluationDataset;
use App\Services\Synthetic\SearchEvaluationMetrics;
use Illuminate\Validation\ValidationException;

function searchEvaluationData(): array
{
    return json_decode(file_get_contents(base_path('tests/Fixtures/synthetic/search-evaluation.json')), true, flags: JSON_THROW_ON_ERROR);
}

test('frozen corpus validates and preserves source topic membership', function () {
    $dataset = new SearchEvaluationDataset(searchEvaluationData());
    expect($dataset->data['sources'])->toHaveCount(20)
        ->and($dataset->data['cases'])->toHaveCount(76)
        ->and(collect($dataset->data['sources'])->where('split', 'held_out'))->toHaveCount(6)
        ->and($dataset->hash())->toBe((new SearchEvaluationDataset(searchEvaluationData()))->hash());
    foreach ($dataset->data['sources'] as $source) {
        expect(json_encode($dataset->publication($source)))->not->toContain('reference_answer', 'relevance', 'gold', 'review');
    }
});

test('dataset rejects missing keys, ungrounded spans, split leakage and injected gold answers', function (Closure $mutate, string $exception) {
    $data = searchEvaluationData();
    $mutate($data);
    expect(fn () => new SearchEvaluationDataset($data))->toThrow($exception);
})->with([
    'schema' => [function (array &$data): void {
        unset($data['seed']);
    }, ValidationException::class],
    'source key' => [function (array &$data): void {
        $data['cases'][0]['relevance'] = ['missing' => 2];
    }, InvalidArgumentException::class],
    'locator' => [function (array &$data): void {
        $data['cases'][0]['gold'][0]['locator'] = 'missing';
    }, InvalidArgumentException::class],
    'span' => [function (array &$data): void {
        $data['cases'][0]['gold'][0]['text'] = 'This sentence never appeared.';
    }, InvalidArgumentException::class],
    'split' => [function (array &$data): void {
        $data['cases'][0]['split'] = 'held_out';
    }, InvalidArgumentException::class],
    'answer injection' => [function (array &$data): void {
        $data['sources'][0]['sections'][0]['text'] .= ' '.$data['cases'][0]['reference_answer'];
    }, InvalidArgumentException::class],
    'question injection' => [function (array &$data): void {
        $data['sources'][0]['sections'][0]['text'] .= ' '.$data['cases'][1]['query'];
    }, InvalidArgumentException::class],
    'negative evidence' => [function (array &$data): void {
        $data['cases'][65]['relevance'] = ['landing' => 2];
    }, InvalidArgumentException::class],
]);

test('rendered fixture requires JavaScript and bundle publication includes source pages', function () {
    $dataset = new SearchEvaluationDataset(searchEvaluationData());
    $rendered = collect($dataset->data['sources'])->firstWhere('key', 'rendered');
    $payload = $dataset->publication($rendered);
    expect($payload['html'])->toContain('<div id="root"></div>', '.innerHTML=');
    expect(ExtractionHeuristics::needsRender($payload['html']))->toBeTrue();
    $bundle = collect($dataset->data['sources'])->firstWhere('key', 'bundle');
    expect($dataset->publication($bundle)['files'])->toHaveCount(3);
});

test('provider neutral import requires grounded context and explicit review', function () {
    $dataset = new SearchEvaluationDataset(searchEvaluationData());
    $row = ['question' => 'How is the mobile file chooser exposed?', 'answer' => 'A visible keyboard usable control is offered.', 'ground_truth_context' => 'Keyboard users can choose a file with the same visible control.'];
    $case = $dataset->import($row, 'landing', 'operation', 'import-1', 'source checked');
    expect($case['gold'][0]['text'])->toBe($row['ground_truth_context']);
    expect(fn () => $dataset->import($row, 'landing', 'decision', 'bad', 'source checked'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $dataset->import($row, 'landing', 'operation', 'bad', ''))->toThrow(InvalidArgumentException::class);
});

test('metrics use source identity, graded discounted gains and normalized snippet evidence', function () {
    $case = ['relevance' => ['a' => 2, 'b' => 1], 'no_answer' => false, 'gold' => [['source' => 'a', 'text' => 'café is ready']]];
    $results = [['id' => 'irrelevant', 'snippet' => 'café is ready'], ['id' => 'id-b'], ['id' => 'id-a', 'snippet' => "cafe\u{0301}\n is   ready"]];
    $score = SearchEvaluationMetrics::score($case, $results, ['a' => 'id-a', 'b' => 'id-b']);
    expect($score['recall_at_5'])->toBe(1.0)
        ->and($score['reciprocal_rank'])->toBe(0.5)
        ->and($score['ndcg_at_5'])->toBeGreaterThan(0.58)->toBeLessThan(0.59)
        ->and($score['snippet_evidence_coverage'])->toBe(1.0);
    $results[2]['id'] = 'irrelevant';
    expect(SearchEvaluationMetrics::score($case, $results, ['a' => 'id-a', 'b' => 'id-b'])['snippet_evidence_coverage'])->toBe(0.0);
});

test('metrics do not count duplicate artifacts twice and enforce k five', function () {
    $case = ['relevance' => ['a' => 2, 'b' => 2], 'no_answer' => false, 'gold' => []];
    $results = array_fill(0, 5, ['id' => 'id-a']);
    $results[] = ['id' => 'id-b'];
    $score = SearchEvaluationMetrics::score($case, $results, ['a' => 'id-a', 'b' => 'id-b']);
    expect($score['recall_at_5'])->toBe(0.5);
    expect(fn () => SearchEvaluationMetrics::score($case, [], ['a' => 'id-a']))->toThrow(InvalidArgumentException::class);
});

test('partial or failed runs never report aggregate retrieval metrics', function () {
    expect(SearchEvaluationMetrics::aggregate([], 1)['scored'])->toBeFalse();
    $failed = SearchEvaluationMetrics::aggregate([['error' => 'transport_failure']], 1);
    expect($failed['scored'])->toBeFalse()->and($failed['metrics'])->toBeNull()->and($failed['tool_errors'])->toBe(1);
});

test('aggregate metrics separate answerable cases and negative false positives with nearest rank latency', function () {
    $rows = [];
    for ($i = 1; $i <= 20; $i++) {
        $rows[] = ['no_answer' => $i > 10, 'latency_ms' => $i, 'metrics' => ['recall_at_5' => 0.5, 'reciprocal_rank' => 0.25, 'ndcg_at_5' => 0.75, 'snippet_evidence_coverage' => 0.5, 'negative_false_positive' => $i === 20]];
    }
    $aggregate = SearchEvaluationMetrics::aggregate($rows, 20);
    expect($aggregate['scored'])->toBeTrue()->and($aggregate['metrics'])->toBe([
        'recall_at_5' => 0.5, 'mrr' => 0.25, 'ndcg_at_5' => 0.75, 'snippet_evidence_coverage' => 0.5,
        'negative_false_positive_rate' => 0.1, 'p50_latency_ms' => 10, 'p95_latency_ms' => 19,
    ]);
});

test('comparison rejects changed frozen inputs and fails release gates without hiding failures', function () {
    $metrics = ['recall_at_5' => 0.95, 'mrr' => 0.8, 'ndcg_at_5' => 0.8, 'negative_false_positive_rate' => 0.0, 'p95_latency_ms' => 100];
    $baseline = ['run_id' => 'baseline', 'dataset_hash' => 'dataset', 'manifest_hash' => 'manifest', 'split' => 'held_out', 'k' => 5, 'concurrency' => 1, 'cases' => [['id' => 'a']], 'readiness' => ['ranking_inputs_hash' => 'inputs', 'configuration' => ['model' => 'same']], 'aggregate' => ['scored' => true, 'metrics' => $metrics]];
    $candidate = $baseline;
    $candidate['run_id'] = 'candidate';
    expect(SearchEvaluationMetrics::compare($baseline, $candidate)['passed'])->toBeTrue();
    $candidate['aggregate']['metrics']['recall_at_5'] = 0.89;
    expect(SearchEvaluationMetrics::compare($baseline, $candidate)['gates']['recall_at_least_90_percent'])->toBeFalse();
    $candidate['aggregate']['metrics']['p95_latency_ms'] = 121;
    expect(SearchEvaluationMetrics::compare($baseline, $candidate)['gates']['latency_within_120_percent'])->toBeFalse();
    $candidate['readiness']['ranking_inputs_hash'] = 'changed';
    expect(fn () => SearchEvaluationMetrics::compare($baseline, $candidate))->toThrow(InvalidArgumentException::class);
});
