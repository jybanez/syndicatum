<?php

return [
    'version' => '202609180001_delivery_worker_heartbeat',
    'description' => 'Record successful delivery worker cycles for operational health',
    'statements' => [
        "CREATE TABLE IF NOT EXISTS delivery_worker_heartbeats (
            worker_name VARCHAR(50) PRIMARY KEY,
            last_success_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ],
];
