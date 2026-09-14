<?php

return [
    'version' => '202609110002_legacy_api_observability',
    'description' => 'Track aggregate legacy API use for compatibility retirement',
    'statements' => [
        "CREATE TABLE IF NOT EXISTS legacy_api_usage_daily (
            usage_date DATE NOT NULL,
            endpoint VARCHAR(120) NOT NULL,
            method VARCHAR(10) NOT NULL,
            request_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
            first_used_at DATETIME NOT NULL,
            last_used_at DATETIME NOT NULL,
            PRIMARY KEY (usage_date, endpoint, method),
            INDEX idx_legacy_api_usage_last (last_used_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ],
];
