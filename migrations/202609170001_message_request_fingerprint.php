<?php

return [
    'version' => '202609170001_message_request_fingerprint',
    'description' => 'Preserve original message request semantics for idempotency conflict checks',
    'statements' => [
        [
            'unless_column' => ['messages', 'request_fingerprint'],
            'sql' => 'ALTER TABLE messages ADD request_fingerprint CHAR(64) NULL AFTER client_idempotency_key',
        ],
    ],
];
