<?php

return [
    'version' => '202609260004',
    'description' => 'Add universal integration capability credentials and inbound event receipts',
    'statements' => [
        "CREATE TABLE IF NOT EXISTS integration_credentials (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            integration_id BIGINT UNSIGNED NOT NULL,
            secret_hash CHAR(64) NOT NULL,
            secret_prefix VARCHAR(12) NOT NULL,
            status ENUM('active', 'revoked') NOT NULL DEFAULT 'active',
            created_by_user_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL,
            revoked_at DATETIME NULL,
            last_used_at DATETIME NULL,
            UNIQUE KEY uq_integration_credentials_secret_hash (secret_hash),
            INDEX idx_integration_credentials_active (integration_id, status, id),
            CONSTRAINT fk_integration_credentials_connection FOREIGN KEY (integration_id)
                REFERENCES integration_connections(id) ON DELETE CASCADE,
            CONSTRAINT fk_integration_credentials_creator FOREIGN KEY (created_by_user_id)
                REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS integration_event_receipts (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            public_id CHAR(36) NOT NULL,
            project_id BIGINT UNSIGNED NOT NULL,
            integration_id BIGINT UNSIGNED NOT NULL,
            credential_id BIGINT UNSIGNED NOT NULL,
            idempotency_key_hash CHAR(64) NOT NULL,
            payload_sha256 CHAR(64) NOT NULL,
            payload_bytes INT UNSIGNED NOT NULL,
            event_type VARCHAR(80) NOT NULL,
            severity ENUM('neutral', 'info', 'success', 'warning', 'error', 'critical') NOT NULL DEFAULT 'info',
            source_metadata_json JSON NULL,
            message_id BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uq_integration_event_receipts_public (public_id),
            UNIQUE KEY uq_integration_event_receipts_idempotency (integration_id, idempotency_key_hash),
            INDEX idx_integration_event_receipts_rate (integration_id, created_at),
            CONSTRAINT fk_integration_event_receipts_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
            CONSTRAINT fk_integration_event_receipts_connection FOREIGN KEY (integration_id) REFERENCES integration_connections(id) ON DELETE CASCADE,
            CONSTRAINT fk_integration_event_receipts_credential FOREIGN KEY (credential_id) REFERENCES integration_credentials(id),
            CONSTRAINT fk_integration_event_receipts_message FOREIGN KEY (message_id) REFERENCES messages(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ],
];
