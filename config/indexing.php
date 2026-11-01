<?php

return [

    /*
    | Enable after provider credentials and pgvector are verified. When
    | off, `artifact.created` events are recorded but never dispatched.
    */
    'enabled' => (bool) env('INDEXING_ENABLED', false),

    // Calibrated on RUB-442 tuning cases with the existing BGE reranker; not a probability.
    'minimum_search_relevance' => (float) env('SEARCH_MINIMUM_RELEVANCE', 0.05),

    /*
    | Workers AI embedding timeout for search queries only (seconds). Document
    | indexing keeps the longer CloudflareIndexingClient default. A hung query
    | embed falls back to lexical-only retrieval (RUB-516).
    */
    'embed_query_timeout_seconds' => (int) env('SEARCH_EMBED_QUERY_TIMEOUT_SECONDS', 1),

];
