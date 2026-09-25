<?php

return [
    'version' => '202609250001',
    'description' => 'Add reusable project templates and agent presets',
    'statements' => [
        "CREATE TABLE IF NOT EXISTS project_templates (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id CHAR(36) NOT NULL,
            name VARCHAR(160) NOT NULL,
            description TEXT NULL,
            instructions MEDIUMTEXT NULL,
            status ENUM('active','archived') NOT NULL DEFAULT 'active',
            version BIGINT UNSIGNED NOT NULL DEFAULT 1,
            created_by_user_id BIGINT UNSIGNED NOT NULL,
            updated_by_user_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            archived_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_project_templates_public_id (public_id),
            KEY idx_project_templates_status_name (status, name),
            KEY idx_project_templates_created_by (created_by_user_id),
            CONSTRAINT fk_project_templates_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id),
            CONSTRAINT fk_project_templates_updated_by FOREIGN KEY (updated_by_user_id) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS project_template_agents (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            template_id BIGINT UNSIGNED NOT NULL,
            display_name VARCHAR(120) NOT NULL,
            provider VARCHAR(40) NOT NULL,
            role_title VARCHAR(160) NOT NULL,
            role_summary TEXT NULL,
            role_instructions MEDIUMTEXT NULL,
            supervising_agent_id BIGINT UNSIGNED NULL,
            sort_order INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_project_template_agents_template (template_id, sort_order, id),
            KEY idx_project_template_agents_supervisor (supervising_agent_id),
            CONSTRAINT fk_project_template_agents_template FOREIGN KEY (template_id) REFERENCES project_templates(id) ON DELETE CASCADE,
            CONSTRAINT fk_project_template_agents_supervisor FOREIGN KEY (supervising_agent_id) REFERENCES project_template_agents(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ],
];
