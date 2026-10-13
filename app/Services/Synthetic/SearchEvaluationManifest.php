<?php

namespace App\Services\Synthetic;

use Illuminate\Support\Facades\File;
use RuntimeException;

final class SearchEvaluationManifest
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(public readonly string $path, public array $data) {}

    public static function open(string $path, SearchEvaluationDataset $dataset, string $slug): self
    {
        SyntheticSeeder::assertSafe($slug);
        $data = File::exists($path) ? json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR) : [
            'schema_version' => 1, 'dataset_hash' => $dataset->hash(), 'dataset_version' => $dataset->data['version'],
            'organization' => $slug, 'endpoint' => 'https://staging.artfct.dev/mcp', 'sources' => [], 'collections' => [],
        ];
        if (($data['schema_version'] ?? null) !== 1 || ($data['dataset_hash'] ?? null) !== $dataset->hash() || ($data['organization'] ?? null) !== $slug || ($data['endpoint'] ?? null) !== 'https://staging.artfct.dev/mcp') {
            throw new RuntimeException('Manifest identity does not match the frozen dataset and staging workspace.');
        }
        if (! is_array($data['sources'] ?? null) || ! is_array($data['collections'] ?? null)) {
            throw new RuntimeException('Invalid manifest mappings.');
        }
        $keys = array_column($dataset->data['sources'], 'key');
        foreach ($data['sources'] as $key => $source) {
            if (! in_array($key, $keys, true) || ! is_array($source) || ! preg_match('/^(?:[a-z0-9]{13}|[a-f0-9]{32})$/', $source['id'] ?? '') || ! is_int($source['version'] ?? null) || $source['version'] < 1 || ! preg_match('/^[a-f0-9]{64}$/', $source['payload_hash'] ?? '')) {
                throw new RuntimeException('Manifest contains an invalid source mapping.');
            }
        }

        return new self($path, $data);
    }

    public function save(): void
    {
        File::ensureDirectoryExists(dirname($this->path));
        File::replace($this->path, json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    }

    /**
     * @return array<string, string>
     */
    public function ids(): array
    {
        return array_map(fn (array $source): string => $source['id'], $this->data['sources']);
    }

    public function hash(): string
    {
        return hash('sha256', json_encode($this->data, JSON_THROW_ON_ERROR));
    }
}
