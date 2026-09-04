<?php

namespace App\Services\Indexing;

/**
 * Deterministic pseudo-embeddings: each text maps to a fixed-length vector
 * derived from its own hash, so identical text always embeds identically
 * and different text (almost certainly) embeds differently — enough to
 * exercise chunking/upsert/deletion without a real model.
 */
final class FakeEmbeddings implements EmbeddingsContract
{
    public const DIMENSIONS = 8;

    public int $callCount = 0;

    public function embed(array $texts): array
    {
        $this->callCount++;

        return array_map(function (string $text): array {
            $hash = md5($text);
            $vector = [];
            for ($i = 0; $i < self::DIMENSIONS; $i++) {
                $vector[] = hexdec(substr($hash, $i * 2, 2)) / 255.0;
            }

            return $vector;
        }, $texts);
    }
}
