<?php

return [
    'version' => '202609050007_avatars_and_agent_webhooks',
    'description' => 'Add encrypted per-agent webhook configuration and durable deliveries',
    'statements' => [
        "CREATE TABLE IF NOT EXISTS agent_notification_webhooks (
            agent_id BIGINT UNSIGNED PRIMARY KEY,
            project_id BIGINT UNSIGNED NOT NULL,
            endpoint_url VARCHAR(2048) NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            events_json TEXT NOT NULL,
            signing_secret_encrypted MEDIUMTEXT NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            last_success_at DATETIME NULL,
            last_failure_at DATETIME NULL,
            last_error VARCHAR(500) NULL,
            INDEX idx_agent_notification_webhooks_project (project_id, enabled),
            CONSTRAINT fk_agent_notification_webhooks_agent FOREIGN KEY (project_id, agent_id)
                REFERENCES project_agents(project_id, agent_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS agent_webhook_deliveries (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            delivery_uuid CHAR(36) NOT NULL UNIQUE,
            project_id BIGINT UNSIGNED NOT NULL,
            message_id BIGINT UNSIGNED NOT NULL,
            agent_id BIGINT UNSIGNED NOT NULL,
            event_type VARCHAR(100) NOT NULL,
            payload_json MEDIUMTEXT NOT NULL,
            status ENUM('queued', 'sending', 'retry', 'succeeded', 'dead') NOT NULL DEFAULT 'queued',
            attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
            next_attempt_at DATETIME NOT NULL,
            response_status SMALLINT UNSIGNED NULL,
            last_error VARCHAR(500) NULL,
            created_at DATETIME NOT NULL,
            delivered_at DATETIME NULL,
            UNIQUE KEY uq_agent_webhook_delivery (message_id, agent_id, event_type),
            INDEX idx_agent_webhook_deliveries_pending (status, next_attempt_at, id),
            INDEX idx_agent_webhook_deliveries_agent (agent_id, created_at),
            CONSTRAINT fk_agent_webhook_deliveries_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
            CONSTRAINT fk_agent_webhook_deliveries_message FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE,
            CONSTRAINT fk_agent_webhook_deliveries_agent FOREIGN KEY (project_id, agent_id)
                REFERENCES project_agents(project_id, agent_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ],
];
