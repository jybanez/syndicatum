<?php

return [
    'version' => '202609050004_project_instructions',
    'description' => 'Add project operating instructions',
    'statements' => [
        [
            'unless_column' => ['projects', 'instructions'],
            'sql' => 'ALTER TABLE projects ADD instructions MEDIUMTEXT NULL AFTER description',
        ],
    ],
];
