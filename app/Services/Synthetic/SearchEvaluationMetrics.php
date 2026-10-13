<?php

namespace App\Services\Synthetic;

use InvalidArgumentException;

/** Artifact retrieval and truncated-snippet evidence metrics at k=5. */
final class SearchEvaluationMetrics
{
    /**
     * @param  array<string, mixed>  $case
     * @param  list<array{id: string, snippet?: string}>  $results
     * @param  array<string, string>  $idsByKey
     * @return array<string, float|bool>
     */
    public static function score(array $case, array $results, array $idsByKey): array
    {
        $grades = [];
        foreach ($case['relevance'] as $key => $grade) {
            if (! isset($idsByKey[$key])) {
                throw new InvalidArgumentException('Relevant source missing from publication manifest.');
            }
            $grades[$idsByKey[$key]] = $grade;
        }
        $relevant = array_filter($grades, fn (int $grade): bool => $grade > 0);
        $seen = [];
        $hits = 0;
        $reciprocalRank = 0.0;
        $dcg = 0.0;
        $covered = [];
        $results = array_slice($results, 0, 5);
        foreach ($results as $index => $result) {
            $id = $result['id'];
            $grade = isset($seen[$id]) ? 0 : ($grades[$id] ?? 0);
            $seen[$id] = true;
            if ($grade > 0) {
                $hits++;
                if ($reciprocalRank === 0.0) {
                    $reciprocalRank = 1.0 / ($index + 1);
                }
            }
            $dcg += (2 ** $grade - 1) / log($index + 2, 2);
            foreach ($case['gold'] as $spanIndex => $span) {
                if (($idsByKey[$span['source']] ?? null) === $id && str_contains(SearchEvaluationDataset::normalize($result['snippet'] ?? ''), SearchEvaluationDataset::normalize($span['text']))) {
                    $covered[$spanIndex] = true;
                }
            }
        }
        $ideal = array_values($grades);
        rsort($ideal);
        $idcg = 0.0;
        foreach (array_slice($ideal, 0, 5) as $index => $grade) {
            $idcg += (2 ** $grade - 1) / log($index + 2, 2);
        }

        return [
            'recall_at_5' => $relevant === [] ? 0.0 : $hits / (float) count($relevant),
            'reciprocal_rank' => $reciprocalRank,
            'ndcg_at_5' => $idcg === 0.0 ? 0.0 : $dcg / $idcg,
            'negative_false_positive' => $case['no_answer'] && $results !== [],
            'snippet_evidence_coverage' => $case['gold'] === [] ? 0.0 : count($covered) / (float) count($case['gold']),
        ];
    }

    /**
     * A failed or incomplete run is unscored.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public static function aggregate(array $rows, int $expectedCount): array
    {
        $errors = count(array_filter($rows, fn (array $row): bool => isset($row['error'])));
        if ($expectedCount < 1 || count($rows) !== $expectedCount || $errors > 0) {
            return ['scored' => false, 'expected_cases' => $expectedCount, 'completed_cases' => count($rows), 'tool_errors' => $errors, 'metrics' => null];
        }
        $answerable = array_values(array_filter($rows, fn (array $row): bool => ! $row['no_answer']));
        $negatives = array_values(array_filter($rows, fn (array $row): bool => $row['no_answer']));
        $mean = fn (string $key): ?float => $answerable === [] ? null : array_sum(array_column(array_column($answerable, 'metrics'), $key)) / count($answerable);
        $latencies = array_column($rows, 'latency_ms');
        sort($latencies);

        return ['scored' => true, 'completed_cases' => count($rows), 'tool_errors' => 0, 'metrics' => [
            'recall_at_5' => $mean('recall_at_5'),
            'mrr' => $mean('reciprocal_rank'),
            'ndcg_at_5' => $mean('ndcg_at_5'),
            'snippet_evidence_coverage' => $mean('snippet_evidence_coverage'),
            'negative_false_positive_rate' => $negatives === [] ? null : count(array_filter($negatives, fn (array $row): bool => $row['metrics']['negative_false_positive'])) / count($negatives),
            // Nearest-rank percentiles, defined for every nonempty sequential run.
            'p50_latency_ms' => $latencies[(int) ceil(count($latencies) * 0.5) - 1],
            'p95_latency_ms' => $latencies[(int) ceil(count($latencies) * 0.95) - 1],
        ]];
    }

    /**
     * @param  array<string, mixed>  $baseline
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    public static function compare(array $baseline, array $candidate): array
    {
        foreach (['dataset_hash', 'manifest_hash', 'split', 'k', 'concurrency'] as $key) {
            if (! isset($baseline[$key], $candidate[$key]) || $baseline[$key] !== $candidate[$key]) {
                throw new InvalidArgumentException('Runs do not share frozen benchmark inputs.');
            }
        }
        if (($baseline['readiness']['ranking_inputs_hash'] ?? null) === null || $baseline['readiness']['ranking_inputs_hash'] !== ($candidate['readiness']['ranking_inputs_hash'] ?? null) || ($baseline['readiness']['configuration'] ?? null) !== ($candidate['readiness']['configuration'] ?? null)) {
            throw new InvalidArgumentException('Usage, canonical or provider inputs changed between runs.');
        }
        if (! ($baseline['aggregate']['scored'] ?? false) || ! ($candidate['aggregate']['scored'] ?? false)) {
            return ['passed' => false, 'reason' => 'unscored_run', 'gates' => []];
        }
        if (array_column($baseline['cases'], 'id') !== array_column($candidate['cases'], 'id')) {
            throw new InvalidArgumentException('Run case membership or ordering changed.');
        }
        $before = $baseline['aggregate']['metrics'];
        $after = $candidate['aggregate']['metrics'];
        $gates = [
            'mrr_not_regressed' => $after['mrr'] >= $before['mrr'],
            'ndcg_not_regressed' => $after['ndcg_at_5'] >= $before['ndcg_at_5'],
            'latency_within_120_percent' => $after['p95_latency_ms'] <= $before['p95_latency_ms'] * 1.2,
        ];
        if ($candidate['split'] === 'held_out') {
            $gates['recall_at_least_90_percent'] = $after['recall_at_5'] >= 0.9;
        }
        if ($after['negative_false_positive_rate'] !== null) {
            $gates['negative_false_positive_at_most_10_percent'] = $after['negative_false_positive_rate'] <= 0.1;
        }
        if ($candidate['split'] === 'regression') {
            $cases = array_column($candidate['cases'], null, 'id');
            foreach (['regression-exact-landing' => 'landing', 'regression-shared-drive' => 'shared-drive'] as $id => $source) {
                $gates[$id] = isset($candidate['source_ids'][$source]) && ($cases[$id]['results'][0]['id'] ?? null) === $candidate['source_ids'][$source];
            }
            foreach (['regression-paraphrase-global', 'regression-paraphrase-collection'] as $id) {
                $gates[$id] = ($cases[$id]['metrics']['recall_at_5'] ?? 0) === 1.0;
            }
            $gates['regression-gardening'] = ($cases['regression-gardening']['results'] ?? null) === [];
            $gates['regression-snippet'] = ($cases['regression-snippet']['metrics']['snippet_evidence_coverage'] ?? 0) === 1.0;
        }

        return ['passed' => ! in_array(false, $gates, true), 'baseline_run_id' => $baseline['run_id'], 'candidate_run_id' => $candidate['run_id'], 'gates' => $gates];
    }
}
