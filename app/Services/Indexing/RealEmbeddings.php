<?php

namespace App\Services\Indexing;

use RuntimeException;

final class RealEmbeddings implements EmbeddingsContract
{
    public function embed(array $texts): array
    {
        if (! config('services.cloudflare.workers_ai_token')) {
            throw new RuntimeException('services.cloudflare.workers_ai_token must be configured to embed text.');
        }

        throw new RuntimeException('RealEmbeddings::embed is not implemented — no live Workers AI account in this environment.');
    }
}
