<?php

return [
    'version' => '202609130002_discussion_binding_codes',
    'description' => 'Add short-lived browser discussion binding codes',
    'statements' => [
        "CREATE TABLE IF NOT EXISTS connector_discussion_binding_codes (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            code_hash CHAR(64) NOT NULL UNIQUE,
            code_prefix VARCHAR(24) NOT NULL,
            project_id BIGINT UNSIGNED NOT NULL,
            agent_id BIGINT UNSIGNED NOT NULL,
            provider VARCHAR(40) NOT NULL,
            created_by_user_id BIGINT UNSIGNED NOT NULL,
            consumed_by_device_id CHAR(36) NULL,
            created_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            consumed_at DATETIME NULL,
            INDEX idx_discussion_binding_agent (project_id, agent_id, consumed_at, expires_at),
            INDEX idx_discussion_binding_expiry (expires_at, consumed_at),
            CONSTRAINT fk_discussion_binding_agent FOREIGN KEY (project_id, agent_id)
                REFERENCES project_agents(project_id, agent_id) ON DELETE CASCADE,
            CONSTRAINT fk_discussion_binding_creator FOREIGN KEY (created_by_user_id)
                REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_discussion_binding_device FOREIGN KEY (consumed_by_device_id)
                REFERENCES connector_devices(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ],
];
