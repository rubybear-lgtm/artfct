<?php

namespace App\Services\Indexing;

/**
 * Workers AI embeddings (spec 12).
 */
interface EmbeddingsContract
{
    /**
     * @param  array<int, string>  $texts
     * @return array<int, array<int, float>> one vector per input text, same order
     */
    public function embed(array $texts): array;
}
