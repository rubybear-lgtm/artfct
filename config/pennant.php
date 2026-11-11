<?php

return [
    // Persist release rules in feature_releases, not resolved per-team decisions.
    'default' => 'releases',
    'stores' => [
        'releases' => ['driver' => 'array'],
    ],
];
