<?php

return [
    'version' => '202609270001',
    'description' => 'Add personal timezone preferences',
    'statements' => [
        [
            'unless_column' => ['users', 'timezone'],
            'sql' => 'ALTER TABLE users ADD timezone VARCHAR(64) NULL AFTER avatar_url',
        ],
    ],
];
