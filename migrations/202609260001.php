<?php

return [
    'version' => '202609260001',
    'description' => 'Add structured server-generated timeline messages',
    'statements' => [
        [
            'unless_column' => ['messages', 'message_kind'],
            'sql' => "ALTER TABLE messages
                ADD message_kind VARCHAR(24) NOT NULL DEFAULT 'participant'
                AFTER sender_participant_id",
        ],
        [
            'unless_column' => ['messages', 'event_type'],
            'sql' => "ALTER TABLE messages
                ADD event_type VARCHAR(100) NULL
                AFTER message_kind",
        ],
        [
            'unless_column' => ['messages', 'event_data_json'],
            'sql' => "ALTER TABLE messages
                ADD event_data_json MEDIUMTEXT NULL
                AFTER event_type",
        ],
        "ALTER TABLE messages
            ADD INDEX idx_messages_kind_event
                (project_id, message_kind, event_type, project_sequence)",
    ],
];
