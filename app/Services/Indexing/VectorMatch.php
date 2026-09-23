<?php

namespace App\Services\Indexing;

final readonly class VectorMatch
{
    public function __construct(
        public VectorChunk $chunk,
        public float $similarity,
    ) {}
}
