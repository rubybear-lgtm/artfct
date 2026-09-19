<?php

return [

    /*
    | Off until the real renderer, embeddings and vector index exist: with it
    | on, the fail-closed Real* classes would dead-letter every deploy. When
    | off, `artifact.created` events are recorded but never dispatched.
    */
    'enabled' => (bool) env('INDEXING_ENABLED', false),

];
