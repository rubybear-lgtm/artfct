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
    ],
];
