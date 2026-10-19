<?php

namespace App\Services\Indexing;

/**
 * Pure chunking with overlap (spec 12: "Chunk with overlap"). No provenance
 * attachment here — that happens where the caller has the artifact's
 * metadata (`IndexingService`) — this class only splits text.
 */
final class Chunker
{
    /**
     * @return array<int, string>
     */
    public static function chunk(string $text, int $chunkSize = 800, int $overlap = 100): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }
        if ($chunkSize <= $overlap) {
            throw new \InvalidArgumentException('chunkSize must be greater than overlap, or chunking never advances.');
        }

        $length = mb_strlen($text);
        if ($length <= $chunkSize) {
            return [$text];
        }

        $chunks = [];
        $start = 0;
        while ($start < $length) {
            $chunks[] = mb_substr($text, $start, $chunkSize);
            $start += $chunkSize - $overlap;
        }

        return $chunks;
    }
}
