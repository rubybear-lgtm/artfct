<?php

return [
    /*
     * Register deploy-dark features here: 'feature-key' => ['name' => 'Feature name',
     * 'description' => 'What this releases.', 'expose_to_browser' => true].
     * Registration never enables a feature. Staff release it separately in /ops.
     */
    'registry' => [
        'artifact-db' => [
            'name' => 'Artifact database',
            'description' => 'Lets an artifact keep shared data (a kanban board, a tracker) that its page and agents read and write. Releasing it turns the database on for the chosen teams; the Worker also has to allow it in this environment.',
        ],
        'artifact-templates' => [
            'name' => 'Artifact templates',
            'description' => 'Lets a team pull Artfct-authored templates (kanban board, team wiki, announcement board) into its library as working artifacts.',
        ],
        'unified-rail' => [
            'name' => 'Unified rail',
            'description' => 'One sidebar, two zones: the global product nav up top and screen-contextual filters or anchors below, with the collections shelf above content rows. Applies to Home, Artifacts, Collections, AI tool connections and Admin.',
            'expose_to_browser' => true,
        ],
    ],
];
