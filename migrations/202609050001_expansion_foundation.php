<?php

return [
    'version' => '202609050001_expansion_foundation',
    'description' => 'Add human, workspace, project, participant, and project-agent foundations',
    'statements' => [
        "CREATE TABLE IF NOT EXISTS users (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            normalized_email VARCHAR(191) NULL UNIQUE,
            username VARCHAR(80) NULL UNIQUE,
            password_hash VARCHAR(255) NULL,
            display_name VARCHAR(120) NOT NULL,
            avatar_url VARCHAR(2048) NULL,
            pbb_user_id VARCHAR(120) NULL UNIQUE,
            status ENUM('active', 'suspended', 'deleted') NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            deleted_at DATETIME NULL,
            INDEX idx_users_status (status, deleted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS system_roles (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(40) NOT NULL UNIQUE,
            name VARCHAR(80) NOT NULL,
            created_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "INSERT IGNORE INTO system_roles (code, name, created_at) VALUES
            ('user', 'User', UTC_TIMESTAMP()),
            ('administrator', 'Administrator', UTC_TIMESTAMP())",
        "CREATE TABLE IF NOT EXISTS user_system_roles (
            user_id BIGINT UNSIGNED NOT NULL,
            role_id BIGINT UNSIGNED NOT NULL,
            granted_by_user_id BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (user_id, role_id),
            INDEX idx_user_system_roles_role (role_id, user_id),
            CONSTRAINT fk_user_system_roles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_user_system_roles_role FOREIGN KEY (role_id) REFERENCES system_roles(id),
            CONSTRAINT fk_user_system_roles_granter FOREIGN KEY (granted_by_user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS workspaces (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            owner_user_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(120) NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uq_workspaces_owner (owner_user_id),
            UNIQUE KEY uq_workspaces_id_owner (id, owner_user_id),
            CONSTRAINT fk_workspaces_owner FOREIGN KEY (owner_user_id) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS projects (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            workspace_id BIGINT UNSIGNED NOT NULL,
            owner_user_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(160) NOT NULL,
            slug VARCHAR(160) NOT NULL,
            description TEXT NULL,
            status ENUM('active', 'archived') NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            archived_at DATETIME NULL,
            UNIQUE KEY uq_projects_workspace_slug (workspace_id, slug),
            INDEX idx_projects_owner_status (owner_user_id, status),
            CONSTRAINT fk_projects_workspace_owner FOREIGN KEY (workspace_id, owner_user_id) REFERENCES workspaces(id, owner_user_id),
            CONSTRAINT fk_projects_owner FOREIGN KEY (owner_user_id) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS project_members (
            project_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            role ENUM('owner', 'admin', 'member', 'viewer') NOT NULL DEFAULT 'member',
            status ENUM('active', 'suspended', 'removed') NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            removed_at DATETIME NULL,
            PRIMARY KEY (project_id, user_id),
            INDEX idx_project_members_user (user_id, status, project_id),
            CONSTRAINT fk_project_members_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
            CONSTRAINT fk_project_members_user FOREIGN KEY (user_id) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS project_agents (
            project_id BIGINT UNSIGNED NOT NULL,
            agent_id BIGINT UNSIGNED NOT NULL,
            display_name VARCHAR(120) NOT NULL,
            avatar_url VARCHAR(2048) NULL,
            provider VARCHAR(80) NULL,
            runtime_name VARCHAR(120) NULL,
            capabilities_json JSON NULL,
            status ENUM('active', 'suspended', 'retired') NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (project_id, agent_id),
            UNIQUE KEY uq_project_agents_agent (agent_id),
            INDEX idx_project_agents_status (project_id, status),
            CONSTRAINT fk_project_agents_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
            CONSTRAINT fk_project_agents_agent FOREIGN KEY (agent_id) REFERENCES chat_agents(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS project_participants (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            project_id BIGINT UNSIGNED NOT NULL,
            kind ENUM('human', 'agent') NOT NULL,
            user_id BIGINT UNSIGNED NULL,
            agent_id BIGINT UNSIGNED NULL,
            status ENUM('active', 'suspended', 'removed') NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uq_project_participants_user (project_id, user_id),
            UNIQUE KEY uq_project_participants_agent (project_id, agent_id),
            INDEX idx_project_participants_directory (project_id, status, kind),
            CONSTRAINT fk_project_participants_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
            CONSTRAINT fk_project_participants_human FOREIGN KEY (project_id, user_id) REFERENCES project_members(project_id, user_id),
            CONSTRAINT fk_project_participants_agent FOREIGN KEY (project_id, agent_id) REFERENCES project_agents(project_id, agent_id),
            CONSTRAINT chk_project_participants_identity CHECK (
                (kind = 'human' AND user_id IS NOT NULL AND agent_id IS NULL)
                OR (kind = 'agent' AND agent_id IS NOT NULL AND user_id IS NULL)
            )
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ],
];
