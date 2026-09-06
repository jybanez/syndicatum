<?php

return [
    'version' => '202609060003_connector_device_activation_routes',
    'description' => 'Store Codex conversation activation routes per authorized connector device',
    'statements' => [
        "CREATE TABLE IF NOT EXISTS connector_device_activation_routes (
            device_id CHAR(36) NOT NULL,
            agent_id BIGINT UNSIGNED NOT NULL,
            project_id BIGINT UNSIGNED NOT NULL,
            runtime_type VARCHAR(40) NOT NULL DEFAULT 'codex',
            conversation_id VARCHAR(255) NOT NULL,
            working_directory VARCHAR(1024) NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (device_id, agent_id),
            INDEX idx_connector_device_routes_project (device_id, project_id, enabled),
            CONSTRAINT fk_connector_device_routes_device FOREIGN KEY (device_id)
                REFERENCES connector_devices(id) ON DELETE CASCADE,
            CONSTRAINT fk_connector_device_routes_agent FOREIGN KEY (project_id, agent_id)
                REFERENCES project_agents(project_id, agent_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ],
];
