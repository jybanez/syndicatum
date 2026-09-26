<?php

return [
    'version' => '202609260002',
    'description' => 'Add canonical message severity variants',
    'statements' => [
        [
            'unless_column' => ['messages', 'severity'],
            'sql' => "ALTER TABLE messages
                ADD severity VARCHAR(16) NOT NULL DEFAULT 'neutral'
                AFTER message_kind,
                ADD CONSTRAINT chk_messages_severity
                    CHECK (severity IN ('neutral', 'info', 'success', 'warning', 'error', 'critical'))",
        ],
        "UPDATE messages
         SET severity = 'success'
         WHERE message_kind = 'system'
           AND event_type = 'task.status_changed'
           AND JSON_UNQUOTE(JSON_EXTRACT(event_data_json, '$.to_status')) = 'completed'",
        "UPDATE messages
         SET severity = 'warning'
         WHERE message_kind = 'system'
           AND event_type = 'task.status_changed'
           AND JSON_UNQUOTE(JSON_EXTRACT(event_data_json, '$.to_status')) IN ('blocked', 'cancelled')",
    ],
];
