<?php

return [
    'version' => '202609050003_account_oauth_attempts',
    'description' => 'Add browser-bound one-time PBB Account OAuth attempts',
    'statements' => [
        "CREATE TABLE IF NOT EXISTS account_oauth_attempts (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            attempt_hash CHAR(64) NOT NULL UNIQUE,
            state_hash CHAR(64) NOT NULL UNIQUE,
            nonce CHAR(64) NOT NULL,
            return_path VARCHAR(500) NOT NULL DEFAULT '/',
            created_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            consumed_at DATETIME NULL,
            INDEX idx_account_oauth_attempts_expiry (expires_at, consumed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ],
];
