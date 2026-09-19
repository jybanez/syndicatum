<?php

return [
    'version' => '202609180002_realtime_outbox_attempt_diagnostics',
    'description' => 'Record bounded Realtime outbox attempt timing and failure category',
    'statements' => [
        [
            'unless_column' => ['message_events_outbox', 'last_attempt_at'],
            'sql' => 'ALTER TABLE message_events_outbox ADD last_attempt_at DATETIME NULL AFTER attempt_count',
        ],
        [
            'unless_column' => ['message_events_outbox', 'last_failure_code'],
            'sql' => 'ALTER TABLE message_events_outbox ADD last_failure_code VARCHAR(32) NULL AFTER last_error',
        ],
    ],
];
