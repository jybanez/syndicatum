<?php

return [
    'version' => '202610130001',
    'description' => 'Bind ChatGPT discussion intents to an explicitly armed Companion device and tab',
    'statements' => [
        "CREATE TABLE IF NOT EXISTS connector_discussion_binding_targets (
            id CHAR(36) PRIMARY KEY,
            device_id CHAR(36) NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            provider VARCHAR(40) NOT NULL,
            discussion_id VARCHAR(255) NOT NULL,
            discussion_reference VARCHAR(500) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            consumed_at DATETIME NULL,
            UNIQUE KEY uq_binding_target_user_provider (user_id, provider),
            INDEX idx_binding_target_device (device_id, status, expires_at),
            CONSTRAINT fk_binding_target_device FOREIGN KEY (device_id)
                REFERENCES connector_devices(id) ON DELETE CASCADE,
            CONSTRAINT fk_binding_target_user FOREIGN KEY (user_id)
                REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "ALTER TABLE connector_discussion_binding_intents
            ADD COLUMN target_device_id CHAR(36) NULL AFTER provider,
            ADD COLUMN target_discussion_id VARCHAR(255) NULL AFTER target_device_id,
            ADD COLUMN target_discussion_reference VARCHAR(500) NULL AFTER target_discussion_id,
            ADD INDEX idx_binding_intent_target (target_device_id, status, expires_at),
            ADD CONSTRAINT fk_binding_intent_target_device FOREIGN KEY (target_device_id)
                REFERENCES connector_devices(id) ON DELETE SET NULL",
        "UPDATE connector_discussion_binding_intents
            SET status = 'cancelled', resolved_at = UTC_TIMESTAMP()
            WHERE status = 'pending' AND target_device_id IS NULL",
    ],
];
