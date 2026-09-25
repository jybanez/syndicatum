<?php

return [
    'version' => '202609250009',
    'description' => 'Separate informational messages from explicit action requests',
    'statements' => [
        [
            'unless_column' => ['messages', 'action_requested'],
            'sql' => "ALTER TABLE messages
                ADD action_requested TINYINT(1) NOT NULL DEFAULT 0
                AFTER reply_depth",
        ],
        "UPDATE messages m
         SET m.action_requested = 1
         WHERE m.action_requested = 0
           AND (EXISTS (
                 SELECT 1 FROM responsibility_events re
                 WHERE re.project_id = m.project_id
                   AND re.request_message_id = m.id
               )
                OR (m.legacy_entry_id IS NOT NULL
                    AND EXISTS (
                        SELECT 1 FROM message_addressees ma
                        WHERE ma.message_id = m.id AND ma.reason = 'direct'
                    )))",
    ],
];
