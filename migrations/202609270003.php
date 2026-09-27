<?php

return [
    'version' => '202609270003',
    'description' => 'Add in-app notification read state and user-scoped Realtime routing',
    'statements' => [
        [
            'unless_column' => ['message_events_outbox', 'room_override'],
            'sql' => 'ALTER TABLE message_events_outbox ADD room_override VARCHAR(180) NULL AFTER project_id',
        ],
        [
            'unless_column' => ['project_invitations', 'notification_read_at'],
            'sql' => 'ALTER TABLE project_invitations ADD notification_read_at DATETIME NULL AFTER responded_at',
        ],
    ],
];
