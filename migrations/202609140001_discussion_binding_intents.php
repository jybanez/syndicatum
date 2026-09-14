<?php

return [
    'version' => '202609140001_discussion_binding_intents',
    'description' => 'Add MCP initiated browser discussion binding intents',
    'statements' => [
        "CREATE TABLE IF NOT EXISTS connector_discussion_binding_intents (
            id CHAR(36) PRIMARY KEY,
            context_token_hash CHAR(64) NOT NULL UNIQUE,
            oauth_access_token_id BIGINT UNSIGNED NOT NULL,
            created_by_user_id BIGINT UNSIGNED NOT NULL,
            project_id BIGINT UNSIGNED NOT NULL,
            requested_agent_id BIGINT UNSIGNED NULL,
            requested_agent_name VARCHAR(120) NOT NULL,
            provider VARCHAR(40) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            confirmed_agent_id BIGINT UNSIGNED NULL,
            discussion_id VARCHAR(255) NULL,
            discussion_reference VARCHAR(500) NULL,
            resolved_by_device_id CHAR(36) NULL,
            created_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            resolved_at DATETIME NULL,
            INDEX idx_binding_intent_user (created_by_user_id, status, expires_at),
            INDEX idx_binding_intent_oauth (oauth_access_token_id, status),
            CONSTRAINT fk_binding_intent_oauth FOREIGN KEY (oauth_access_token_id)
                REFERENCES oauth_access_tokens(id) ON DELETE CASCADE,
            CONSTRAINT fk_binding_intent_user FOREIGN KEY (created_by_user_id)
                REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_binding_intent_project FOREIGN KEY (project_id)
                REFERENCES projects(id) ON DELETE CASCADE,
            CONSTRAINT fk_binding_intent_requested_agent FOREIGN KEY (project_id, requested_agent_id)
                REFERENCES project_agents(project_id, agent_id) ON DELETE CASCADE,
            CONSTRAINT fk_binding_intent_confirmed_agent FOREIGN KEY (project_id, confirmed_agent_id)
                REFERENCES project_agents(project_id, agent_id) ON DELETE CASCADE,
            CONSTRAINT fk_binding_intent_device FOREIGN KEY (resolved_by_device_id)
                REFERENCES connector_devices(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ],
];
