<?php

return [
    'version' => '202610070001',
    'description' => 'Add authoritative notification handling leases for browser companion delivery gating',
    'statements' => [
        "CREATE TABLE IF NOT EXISTS notification_handling_leases (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            handling_uuid CHAR(36) NOT NULL,
            project_id BIGINT UNSIGNED NOT NULL,
            participant_id BIGINT UNSIGNED NOT NULL,
            agent_id BIGINT UNSIGNED NOT NULL,
            message_id BIGINT UNSIGNED NOT NULL,
            project_sequence BIGINT UNSIGNED NOT NULL,
            provider VARCHAR(40) NOT NULL,
            conversation_id VARCHAR(255) NOT NULL,
            conversation_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            state ENUM('responding','working','waiting','available','expired') NOT NULL,
            active_slot TINYINT UNSIGNED NULL,
            lease_expires_at DATETIME NULL,
            completion_outcome VARCHAR(40) NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            completed_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_notification_handling_uuid (handling_uuid),
            UNIQUE KEY uq_notification_handling_message (message_id, participant_id),
            UNIQUE KEY uq_notification_handling_active (project_id, agent_id, provider, conversation_hash, active_slot),
            KEY idx_notification_handling_device (project_id, agent_id, provider, state, lease_expires_at),
            CONSTRAINT fk_notification_handling_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
            CONSTRAINT fk_notification_handling_participant FOREIGN KEY (participant_id) REFERENCES project_participants(id) ON DELETE CASCADE,
            CONSTRAINT fk_notification_handling_agent FOREIGN KEY (project_id, agent_id) REFERENCES project_agents(project_id, agent_id) ON DELETE CASCADE,
            CONSTRAINT fk_notification_handling_message FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ],
];
