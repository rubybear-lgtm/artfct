<?php

namespace App\Console\Commands;

use App\Services\Synthetic\SearchEvaluationClient;
use App\Services\Synthetic\SearchEvaluationDataset;
use App\Services\Synthetic\SearchEvaluationManifest;
use App\Services\Synthetic\SearchEvaluationMetrics;
use App\Services\Synthetic\SearchEvaluationReadiness;
use App\Services\Synthetic\SearchEvaluationRunner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Sleep;
use RuntimeException;

#[Signature('synthetic:search-evaluation {operation : validate|setup|readiness|run|compare|cleanup} {org=zz-search-rub-442} {--baseline= : Baseline run JSON} {--candidate= : Candidate run JSON} {--dataset= : Frozen dataset JSON} {--manifest= : Publication manifest JSON} {--output= : Readiness or run output JSON} {--readiness= : Fresh server-side readiness JSON} {--split=tuning : tuning|held_out|regression} {--deployment= : Verified running deployment identifier} {--wait=600 : Readiness polling deadline in seconds, at most 600}')]
#[Description('Evaluate frozen artifact search fixtures through authenticated staging Streamable HTTP MCP')]
class SyntheticSearchEvaluationCommand extends Command
{
    public function handle(SearchEvaluationReadiness $readiness): int
    {
        try {
            $dataset = SearchEvaluationDataset::load((string) ($this->option('dataset') ?: base_path('tests/Fixtures/synthetic/search-evaluation.json')));
            $operation = (string) $this->argument('operation');
            if ($operation === 'validate') {
                $this->line(json_encode(['dataset_version' => $dataset->data['version'], 'dataset_hash' => $dataset->hash(), 'sources' => count($dataset->data['sources']), 'cases' => count($dataset->data['cases']), 'review' => $dataset->data['provenance']['human_review'] ?? 'unspecified'], JSON_THROW_ON_ERROR));

                return self::SUCCESS;
            }
            if ($operation === 'compare') {
                $comparison = SearchEvaluationMetrics::compare(
                    json_decode(File::get((string) $this->option('baseline')), true, flags: JSON_THROW_ON_ERROR),
                    json_decode(File::get((string) $this->option('candidate')), true, flags: JSON_THROW_ON_ERROR),
                );
                $this->line(json_encode($comparison, JSON_THROW_ON_ERROR));

                return $comparison['passed'] ? self::SUCCESS : self::FAILURE;
            }
            $slug = (string) $this->argument('org');
            $manifest = SearchEvaluationManifest::open((string) ($this->option('manifest') ?: storage_path('app/synthetic/search-evaluation/'.$slug.'/manifest.json')), $dataset, $slug);
            $output = (string) $this->option('output');
            $runner = new SearchEvaluationRunner(new SearchEvaluationClient((string) config('synthetic.search_evaluation.endpoint'), (string) config('synthetic.search_evaluation.token')));
            switch ($operation) {
                case 'setup':
                    $runner->setup($dataset, $manifest);
                    $this->info('Corpus published and mapped. Run readiness on the authorized staging console before querying.');
                    break;
                case 'readiness':
                    if ($output === '') {
                        throw new RuntimeException('--output is required.');
                    }
                    $seconds = filter_var($this->option('wait'), FILTER_VALIDATE_INT);
                    if ($seconds === false || $seconds < 0 || $seconds > 600) {
                        throw new RuntimeException('--wait must be between 0 and 600 seconds.');
                    }
                    $deadline = microtime(true) + $seconds;
                    do {
                        $report = $readiness->inspect($dataset, $manifest);
                        File::ensureDirectoryExists(dirname($output));
                        File::replace($output, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
                        if ($report['ready']) {
                            $this->info('Every published version, gold span and embedded chunk is ready.');

                            return self::SUCCESS;
                        }
                        if (microtime(true) >= $deadline) {
                            $this->error('Indexing readiness failed; pending source diagnostics are in '.$output);

                            return self::FAILURE;
                        }
                        Sleep::for(min(5, max(0, $deadline - microtime(true))))->seconds();
                    } while (true);
                case 'run':
                    if ($output === '' || ! File::exists((string) $this->option('readiness'))) {
                        throw new RuntimeException('--output and --readiness are required.');
                    }
                    $run = $runner->run($dataset, $manifest, json_decode(File::get((string) $this->option('readiness')), true, flags: JSON_THROW_ON_ERROR), (string) $this->option('split'), (string) $this->option('deployment'), $output);
                    $this->line(json_encode(['run_id' => $run['run_id'], 'aggregate' => $run['aggregate']], JSON_THROW_ON_ERROR));

                    return $run['aggregate']['scored'] ? self::SUCCESS : self::FAILURE;
                case 'cleanup':
                    $runner->cleanup($manifest);
                    $this->info('Only manifest artifacts deleted. Evaluation collections are retained: '.implode(', ', array_keys($manifest->data['collections'])));
                    break;
                default:
                    throw new RuntimeException('Unknown evaluation operation.');
            }

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            // Transport errors deliberately contain no credential or raw provider payload.
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
