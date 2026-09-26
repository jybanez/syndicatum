<?php

return [
    'version' => '202609260003',
    'description' => 'Add project-scoped external integration participants',
    'statements' => [
        "CREATE TABLE IF NOT EXISTS integration_connections (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            public_id CHAR(36) NOT NULL,
            project_id BIGINT UNSIGNED NOT NULL,
            provider VARCHAR(80) NOT NULL,
            display_name VARCHAR(120) NOT NULL,
            description VARCHAR(500) NULL,
            external_reference VARCHAR(255) NULL,
            capabilities_json JSON NOT NULL,
            status ENUM('active', 'disabled', 'removed') NOT NULL DEFAULT 'active',
            created_by_user_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uq_integration_connections_public (public_id),
            UNIQUE KEY uq_integration_connections_project_id (project_id, id),
            INDEX idx_integration_connections_directory (project_id, status, provider),
            CONSTRAINT fk_integration_connections_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
            CONSTRAINT fk_integration_connections_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        [
            'unless_column' => ['project_participants', 'integration_id'],
            'sql' => "ALTER TABLE project_participants
                MODIFY kind ENUM('human', 'agent', 'integration') NOT NULL,
                ADD integration_id BIGINT UNSIGNED NULL AFTER agent_id,
                ADD UNIQUE KEY uq_project_participants_integration (project_id, integration_id),
                ADD CONSTRAINT fk_project_participants_integration
                    FOREIGN KEY (project_id, integration_id)
                    REFERENCES integration_connections(project_id, id)",
        ],
        // CHECK constraints are parsed but not enforced by the legacy MySQL 5.7
        // development/test substrate. Production MySQL 8.4 must replace the
        // original two-kind identity constraint before integration rows exist.
        "SET @syndicatum_participant_identity_sql = IF(
            CAST(SUBSTRING_INDEX(VERSION(), '.', 1) AS UNSIGNED) >= 8,
            'ALTER TABLE project_participants DROP CHECK chk_project_participants_identity, ADD CONSTRAINT chk_project_participants_identity CHECK (
                (kind = ''human'' AND user_id IS NOT NULL AND agent_id IS NULL AND integration_id IS NULL)
                OR (kind = ''agent'' AND agent_id IS NOT NULL AND user_id IS NULL AND integration_id IS NULL)
                OR (kind = ''integration'' AND integration_id IS NOT NULL AND user_id IS NULL AND agent_id IS NULL)
            )',
            'DO 1')",
        'PREPARE syndicatum_participant_identity_statement FROM @syndicatum_participant_identity_sql',
        'EXECUTE syndicatum_participant_identity_statement',
        'DEALLOCATE PREPARE syndicatum_participant_identity_statement',
    ],
];
