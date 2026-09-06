<?php

return [
    'version' => '202609060001_connector_devices',
    'description' => 'Add revocable human-authorized Codex connector devices',
    'statements' => [
        "CREATE TABLE IF NOT EXISTS connector_device_authorizations (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            device_code_hash CHAR(64) NOT NULL UNIQUE,
            user_code_hash CHAR(64) NOT NULL UNIQUE,
            device_name VARCHAR(120) NOT NULL,
            platform VARCHAR(40) NOT NULL DEFAULT 'unknown',
            status ENUM('pending', 'approved', 'denied', 'consumed') NOT NULL DEFAULT 'pending',
            approved_user_id BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            approved_at DATETIME NULL,
            consumed_at DATETIME NULL,
            INDEX idx_connector_authorizations_expiry (status, expires_at),
            CONSTRAINT fk_connector_authorizations_user FOREIGN KEY (approved_user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS connector_devices (
            id CHAR(36) PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            display_name VARCHAR(120) NOT NULL,
            platform VARCHAR(40) NOT NULL DEFAULT 'unknown',
            token_prefix VARCHAR(24) NOT NULL,
            token_hash CHAR(64) NOT NULL UNIQUE,
            created_at DATETIME NOT NULL,
            last_seen_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            revoked_at DATETIME NULL,
            INDEX idx_connector_devices_user (user_id, revoked_at, expires_at),
            CONSTRAINT fk_connector_devices_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ],
];
