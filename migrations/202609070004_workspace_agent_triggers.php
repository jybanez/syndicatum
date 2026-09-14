<?php

return [
    'version' => '202609070004_workspace_agent_triggers',
    'description' => 'Add encrypted ChatGPT Workspace Agent activation and durable trigger deliveries',
    'statements' => [
        [
            'unless_column' => ['agent_activation_bindings', 'workspace_agent_trigger_id'],
            'sql' => "ALTER TABLE agent_activation_bindings
                ADD COLUMN workspace_agent_trigger_id VARCHAR(255) NULL AFTER working_directory,
                ADD COLUMN workspace_agent_conversation_key VARCHAR(255) NULL AFTER workspace_agent_trigger_id,
                ADD COLUMN workspace_agent_token_encrypted MEDIUMTEXT NULL AFTER workspace_agent_conversation_key,
                ADD COLUMN workspace_agent_last_success_at DATETIME NULL AFTER workspace_agent_token_encrypted,
                ADD COLUMN workspace_agent_last_failure_at DATETIME NULL AFTER workspace_agent_last_success_at,
                ADD COLUMN workspace_agent_last_error VARCHAR(500) NULL AFTER workspace_agent_last_failure_at",
        ],
        "CREATE TABLE IF NOT EXISTS workspace_agent_trigger_deliveries (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            delivery_uuid CHAR(36) NOT NULL UNIQUE,
            project_id BIGINT UNSIGNED NOT NULL,
            message_id BIGINT UNSIGNED NOT NULL,
            agent_id BIGINT UNSIGNED NOT NULL,
            status ENUM('queued', 'sending', 'retry', 'succeeded', 'dead') NOT NULL DEFAULT 'queued',
            attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
            next_attempt_at DATETIME NOT NULL,
            response_status SMALLINT UNSIGNED NULL,
            run_id VARCHAR(255) NULL,
            conversation_url VARCHAR(2048) NULL,
            last_error VARCHAR(500) NULL,
            created_at DATETIME NOT NULL,
            delivered_at DATETIME NULL,
            UNIQUE KEY uq_workspace_agent_trigger_delivery (message_id, agent_id),
            INDEX idx_workspace_agent_trigger_pending (status, next_attempt_at, id),
            CONSTRAINT fk_workspace_agent_trigger_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
            CONSTRAINT fk_workspace_agent_trigger_message FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE,
            CONSTRAINT fk_workspace_agent_trigger_agent FOREIGN KEY (project_id, agent_id)
                REFERENCES project_agents(project_id, agent_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ],
];
