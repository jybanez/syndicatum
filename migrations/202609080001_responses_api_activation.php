<?php

return [
    'version' => '202609080001_responses_api_activation',
    'description' => 'Add server-run OpenAI Responses API activation for ChatGPT agents',
    'statements' => [
        [
            'unless_column' => ['agent_activation_bindings', 'activation_driver'],
            'sql' => "ALTER TABLE agent_activation_bindings
                ADD COLUMN activation_driver VARCHAR(40) NOT NULL DEFAULT 'workspace_agent' AFTER runtime_type,
                ADD COLUMN responses_model VARCHAR(120) NULL AFTER workspace_agent_last_error,
                ADD COLUMN responses_api_key_encrypted MEDIUMTEXT NULL AFTER responses_model,
                ADD COLUMN responses_mcp_token_encrypted MEDIUMTEXT NULL AFTER responses_api_key_encrypted,
                ADD COLUMN responses_last_response_id VARCHAR(255) NULL AFTER responses_mcp_token_encrypted,
                ADD COLUMN responses_last_success_at DATETIME NULL AFTER responses_last_response_id,
                ADD COLUMN responses_last_failure_at DATETIME NULL AFTER responses_last_success_at,
                ADD COLUMN responses_last_error VARCHAR(500) NULL AFTER responses_last_failure_at",
        ],
        "CREATE TABLE IF NOT EXISTS mcp_service_tokens (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            token_hash CHAR(64) NOT NULL UNIQUE,
            project_id BIGINT UNSIGNED NOT NULL,
            agent_id BIGINT UNSIGNED NOT NULL,
            created_by_user_id BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            last_used_at DATETIME NULL,
            revoked_at DATETIME NULL,
            INDEX idx_mcp_service_agent (project_id, agent_id, revoked_at),
            CONSTRAINT fk_mcp_service_agent FOREIGN KEY (project_id, agent_id)
                REFERENCES project_agents(project_id, agent_id) ON DELETE CASCADE,
            CONSTRAINT fk_mcp_service_creator FOREIGN KEY (created_by_user_id)
                REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS responses_api_deliveries (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            delivery_uuid CHAR(36) NOT NULL UNIQUE,
            project_id BIGINT UNSIGNED NOT NULL,
            message_id BIGINT UNSIGNED NOT NULL,
            agent_id BIGINT UNSIGNED NOT NULL,
            status ENUM('queued', 'sending', 'waiting', 'retry', 'succeeded', 'dead') NOT NULL DEFAULT 'queued',
            attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
            next_attempt_at DATETIME NOT NULL,
            response_status SMALLINT UNSIGNED NULL,
            response_id VARCHAR(255) NULL,
            response_state VARCHAR(40) NULL,
            last_error VARCHAR(500) NULL,
            created_at DATETIME NOT NULL,
            delivered_at DATETIME NULL,
            UNIQUE KEY uq_responses_api_delivery (message_id, agent_id),
            INDEX idx_responses_api_pending (status, next_attempt_at, id),
            CONSTRAINT fk_responses_api_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
            CONSTRAINT fk_responses_api_message FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE,
            CONSTRAINT fk_responses_api_agent FOREIGN KEY (project_id, agent_id)
                REFERENCES project_agents(project_id, agent_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "UPDATE agent_activation_bindings
         SET activation_driver = 'responses_api', conversation_id = 'responses_api'
         WHERE runtime_type = 'chatgpt' AND enabled = 0
           AND (workspace_agent_trigger_id IS NULL OR workspace_agent_token_encrypted IS NULL)",
    ],
];
