<?php

return [

    /*
    | Enable after provider credentials and pgvector are verified. When
    | off, `artifact.created` events are recorded but never dispatched.
    */
    'enabled' => (bool) env('INDEXING_ENABLED', false),

];
