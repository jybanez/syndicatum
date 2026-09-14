<?php

return [
    'version' => '202609140002_remove_legacy_discussion_binding_codes',
    'description' => 'Remove the superseded manual discussion binding code store',
    'statements' => [
        'DROP TABLE IF EXISTS connector_discussion_binding_codes',
    ],
];
