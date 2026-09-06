<?php

return [
    'version' => '202609050008_agent_activation_bindings',
    'description' => 'Link project agents to existing provider conversations for local activation connectors',
    'statements' => [
        "CREATE TABLE IF NOT EXISTS agent_activation_bindings (
            agent_id BIGINT UNSIGNED PRIMARY KEY,
            project_id BIGINT UNSIGNED NOT NULL,
            runtime_type VARCHAR(40) NOT NULL DEFAULT 'codex',
            conversation_id VARCHAR(255) NOT NULL,
            working_directory VARCHAR(1024) NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 0,
            created_by_user_id BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX idx_agent_activation_bindings_project (project_id, enabled),
            CONSTRAINT fk_agent_activation_bindings_agent FOREIGN KEY (project_id, agent_id)
                REFERENCES project_agents(project_id, agent_id) ON DELETE CASCADE,
            CONSTRAINT fk_agent_activation_bindings_creator FOREIGN KEY (created_by_user_id)
                REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ],
];
