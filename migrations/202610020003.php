<?php

return [
    'version' => '202610020003',
    'description' => 'Classify action requests as work, approval, or review',
    'statements' => [
        [
            'unless_column' => ['messages', 'action_request_type'],
            'sql' => "ALTER TABLE messages
                ADD action_request_type ENUM('work','approval','review') NULL
                AFTER action_requested",
        ],
        "UPDATE messages
         SET action_request_type = 'work'
         WHERE action_requested = 1 AND action_request_type IS NULL",
    ],
];
