<?php

return [
    'version' => '202609270002',
    'description' => 'Add native registration activation and lifecycle email delivery state',
    'statements' => [
        "CREATE TABLE IF NOT EXISTS user_registration_activations (
            user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
            expires_at DATETIME NOT NULL,
            consumed_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX idx_user_registration_activation_pending (consumed_at, expires_at),
            CONSTRAINT fk_user_registration_activation_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS user_lifecycle_notifications (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            event_code VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            status ENUM('sending', 'succeeded', 'failed') NOT NULL DEFAULT 'sending',
            attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
            last_error VARCHAR(500) NULL,
            delivered_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uq_user_lifecycle_notification (user_id, event_code),
            INDEX idx_user_lifecycle_notification_status (status, updated_at),
            CONSTRAINT fk_user_lifecycle_notification_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ],
];
