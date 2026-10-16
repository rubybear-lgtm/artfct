<?php

namespace App\Services\Indexing;

use RuntimeException;

final class RealReranker implements RerankerContract
{
    public function __construct(private readonly CloudflareIndexingClient $client) {}

    public function rerank(string $query, array $candidates): array
    {
        if ($candidates === []) {
            return [];
        }
        $candidates = array_values($candidates);
        $model = config('services.cloudflare.reranker_model');
        $result = $this->client->post("ai/run/{$model}", [
            'query' => $query,
            'contexts' => array_map(fn (VectorMatch $match): array => ['text' => $match->chunk->text], $candidates),
        ]);
        $scores = is_array($result) ? ($result['response'] ?? null) : null;
        if (! is_array($scores) || count($scores) !== count($candidates)) {
            throw new RuntimeException('Workers AI returned an unexpected reranker count.');
        }
        $reranked = [];
        foreach ($scores as $entry) {
            $id = $entry['id'] ?? null;
            $score = $entry['score'] ?? null;
            if (! is_int($id) || ! isset($candidates[$id]) || isset($reranked[$id])
                || ! (is_int($score) || is_float($score)) || ! is_finite((float) $score) || $score < 0 || $score > 1) {
                throw new RuntimeException('Workers AI returned an invalid reranker score or candidate id.');
            }
            $reranked[$id] = new VectorMatch($candidates[$id]->chunk, (float) $score);
        }
        uasort($reranked, fn (VectorMatch $a, VectorMatch $b): int => $b->similarity <=> $a->similarity);

        return array_values($reranked);
    }
}
