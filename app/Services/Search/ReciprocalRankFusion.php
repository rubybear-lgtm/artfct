<?php

namespace App\Services\Search;

use App\Services\Indexing\VectorMatch;

final class ReciprocalRankFusion
{
    /**
     * Collapse each retrieval list by artifact before fusing, so a long
     * document cannot occupy the entire reranking budget with its chunks.
     *
     * @param  array<int, VectorMatch>  ...$lists
     * @return array<int, VectorMatch>
     */
    public static function combine(array ...$lists): array
    {
        $scores = [];
        $chunks = [];
        foreach ($lists as $list) {
            $seen = [];
            $rank = 0;
            foreach ($list as $match) {
                $id = $match->chunk->orgId.':'.$match->chunk->artifactId;
                if (isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $scores[$id] = ($scores[$id] ?? 0.0) + 1.0 / (60 + ++$rank);
                $chunks[$id] ??= $match->chunk;
            }
        }
        arsort($scores);

        return array_map(fn (string $id): VectorMatch => new VectorMatch($chunks[$id], $scores[$id]), array_keys($scores));
    }
}
