<?php

namespace App\Services\Synthetic;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

final class SearchEvaluationRunner
{
    public function __construct(private readonly SearchEvaluationClient $client) {}

    public function setup(SearchEvaluationDataset $dataset, SearchEvaluationManifest $manifest): void
    {
        $this->client->initialize();
        $this->client->verifyWorkspace($manifest->data['organization'], ['artifacts:read', 'artifacts:deploy', 'collections:read', 'collections:write']);
        foreach ($dataset->data['sources'] as $source) {
            $payload = $dataset->publication($source);
            $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
            $existing = $manifest->data['sources'][$source['key']] ?? null;
            if ($existing !== null) {
                if ($existing['payload_hash'] !== $hash) {
                    throw new RuntimeException('Frozen publication payload changed. Use a new dataset and manifest.');
                }
                $artifact = $this->client->call('get_artifact', ['id' => $existing['id']]);
            } else {
                $artifact = $this->client->call('deploy_artifact', $payload);
                if (! is_string($artifact['id'] ?? null) || ! is_int($artifact['version'] ?? null) || ($artifact['organization'] ?? null) !== $manifest->data['organization']) {
                    throw new RuntimeException('Publication identity is invalid.');
                }
                $manifest->data['sources'][$source['key']] = ['id' => $artifact['id'], 'version' => $artifact['version'], 'payload_hash' => $hash, 'view_url' => $artifact['view_url'] ?? null];
                $manifest->save();
            }
            if (($artifact['id'] ?? null) !== $manifest->data['sources'][$source['key']]['id'] || ($artifact['version'] ?? null) !== $manifest->data['sources'][$source['key']]['version'] || ($artifact['title'] ?? null) !== $source['title'] || ($artifact['sharing'] ?? null) !== 'team') {
                throw new RuntimeException('Published metadata does not match the frozen source.');
            }
        }
        $available = [];
        $cursor = null;
        do {
            $page = $this->client->call('list_collections', array_filter(['limit' => 50, 'cursor' => $cursor]));
            foreach ($page['collections'] ?? [] as $collection) {
                $available[$collection['name']] = $collection['id'];
            }
            $cursor = $page['next_cursor'] ?? null;
        } while ($cursor !== null);
        foreach ($dataset->data['sources'] as $source) {
            foreach ($source['collections'] as $name) {
                if (! isset($available[$name])) {
                    $collection = $this->client->call('create_collection', ['name' => $name, 'description' => 'Disposable search evaluation corpus; retained after artifact cleanup.']);
                    $available[$name] = $collection['id'];
                }
                $manifest->data['collections'][$name] = $available[$name];
                $this->client->call('add_collection_artifact', ['collection_id' => $available[$name], 'artifact_id' => $manifest->data['sources'][$source['key']]['id']]);
                $manifest->save();
            }
        }
    }

    /**
     * @param  array<string, mixed>  $readiness
     * @return array<string, mixed>
     */
    public function run(SearchEvaluationDataset $dataset, SearchEvaluationManifest $manifest, array $readiness, string $split, string $deployment, string $output): array
    {
        if (! in_array($split, ['tuning', 'held_out', 'regression'], true) || trim($deployment) === '') {
            throw new RuntimeException('Specify a frozen split and verified deployment identifier.');
        }
        if (($readiness['ready'] ?? false) !== true || ($readiness['dataset_hash'] ?? null) !== $dataset->hash() || ($readiness['manifest_hash'] ?? null) !== $manifest->hash() || ($readiness['organization'] ?? null) !== $manifest->data['organization'] || now()->diffInSeconds($readiness['checked_at'] ?? '1970-01-01', absolute: true) > 600) {
            throw new RuntimeException('A fresh matching server-side readiness report is required.');
        }
        if ($split === 'held_out' && File::exists($output)) {
            throw new RuntimeException('Refusing to overwrite a held-out run.');
        }
        $this->client->initialize();
        $connection = $this->client->verifyWorkspace($manifest->data['organization'], ['artifacts:read']);
        $cases = array_values(array_filter($dataset->data['cases'], fn (array $case): bool => $case['split'] === $split));
        $run = ['run_id' => (string) Str::uuid(), 'dataset_hash' => $dataset->hash(), 'manifest_hash' => $manifest->hash(), 'source_ids' => $manifest->ids(), 'deployment' => $deployment, 'readiness' => $readiness, 'connection' => $connection, 'split' => $split, 'k' => 5, 'concurrency' => 1, 'started_at' => now()->toIso8601String(), 'cases' => []];
        foreach ($cases as $case) {
            $arguments = ['query' => $case['query'], 'limit' => 5] + $case['filters'];
            $started = hrtime(true);
            $row = ['id' => $case['id'], 'class' => $case['class'], 'no_answer' => $case['no_answer'], 'arguments' => $arguments];
            try {
                $response = $this->client->call('search_artifacts', $arguments);
                if (! is_array($response['results'] ?? null)) {
                    throw new RuntimeException('Search result schema is invalid.');
                }
                $row['results'] = $response['results'];
                $row['metrics'] = SearchEvaluationMetrics::score($case, $response['results'], $manifest->ids());
            } catch (RuntimeException $exception) {
                $row['error'] = $exception->getMessage();
            }
            $row['latency_ms'] = (hrtime(true) - $started) / 1_000_000;
            $run['cases'][] = $row;
            $run['aggregate'] = SearchEvaluationMetrics::aggregate($run['cases'], count($cases));
            File::ensureDirectoryExists(dirname($output));
            File::replace($output, json_encode($run, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
        }

        return $run;
    }

    public function cleanup(SearchEvaluationManifest $manifest): void
    {
        $this->client->initialize();
        $this->client->verifyWorkspace($manifest->data['organization'], ['artifacts:delete']);
        foreach ($manifest->data['sources'] as $key => $source) {
            $result = $this->client->call('delete_artifact', ['id' => $source['id']]);
            if (($result['deleted'] ?? false) !== true) {
                throw new RuntimeException('Artifact cleanup was not confirmed.');
            }
            unset($manifest->data['sources'][$key]);
            $manifest->save();
        }
    }
}
