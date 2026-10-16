<?php

namespace App\Services\Indexing;

use RuntimeException;

final class RealEmbeddings implements EmbeddingsContract
{
    public const DIMENSIONS = 1024;

    private const QUERY_INSTRUCTION = 'Given a web search query, retrieve relevant passages that answer the query';

    public function __construct(private readonly CloudflareIndexingClient $client) {}

    public function embed(array $texts): array
    {
        $vectors = [];
        foreach (array_chunk(array_values($texts), 32) as $batch) {
            array_push($vectors, ...$this->request(['text' => $batch]));
        }

        return $vectors;
    }

    public function embedQuery(string $query): array
    {
        return $this->request(['text' => [$query], 'instruction' => self::QUERY_INSTRUCTION])[0];
    }

    /**
     * @param  array{text: array<int, string>, instruction?: string}  $body
     * @return array<int, array<int, float>>
     */
    private function request(array $body): array
    {
        $model = config('services.cloudflare.embeddings_model');
        $result = $this->client->post("ai/run/{$model}", $body);
        $vectors = is_array($result) ? ($result['data'] ?? null) : null;
        if (! is_array($vectors) || ! array_is_list($vectors) || count($vectors) !== count($body['text'])) {
            throw new RuntimeException('Workers AI returned an unexpected embedding count.');
        }

        foreach ($vectors as $vector) {
            if (! is_array($vector) || ! array_is_list($vector) || count($vector) !== self::DIMENSIONS) {
                throw new RuntimeException('Workers AI embedding must have 1024 dimensions.');
            }
            foreach ($vector as $value) {
                if (! (is_float($value) || is_int($value)) || ! is_finite((float) $value)) {
                    throw new RuntimeException('Workers AI embedding contains an invalid value.');
                }
            }
        }

        return $vectors;
    }
}
