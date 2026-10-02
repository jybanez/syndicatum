<?php

return [
    'version' => '202610020002',
    'description' => 'Track per-participant project timeline read positions',
    'statements' => [
        [
            'unless_column' => ['project_participants', 'last_read_sequence'],
            'sql' => 'ALTER TABLE project_participants ADD last_read_sequence BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER status_generation',
        ],
        "UPDATE project_participants pp
         LEFT JOIN (
             SELECT project_id, MAX(project_sequence) AS latest_sequence
             FROM messages
             WHERE deleted_at IS NULL
             GROUP BY project_id
         ) latest ON latest.project_id = pp.project_id
         SET pp.last_read_sequence = COALESCE(latest.latest_sequence, 0)",
    ],
];
