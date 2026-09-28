<?php

return [
    'version' => '202609280003',
    'description' => 'Add human-reviewed project change proposals from agents',
    'statements' => [
        "CREATE TABLE IF NOT EXISTS project_change_proposals (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id CHAR(36) NOT NULL,
            project_id BIGINT UNSIGNED NOT NULL,
            proposal_type ENUM('project_details','agent_setup','agent_profile_update') NOT NULL,
            target_agent_id BIGINT UNSIGNED NULL,
            proposed_by_participant_id BIGINT UNSIGNED NOT NULL,
            payload_json JSON NOT NULL,
            rationale TEXT NULL,
            status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
            version BIGINT UNSIGNED NOT NULL DEFAULT 1,
            reviewed_by_user_id BIGINT UNSIGNED NULL,
            review_note TEXT NULL,
            applied_agent_id BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            reviewed_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_project_change_proposals_public_id (public_id),
            KEY idx_project_change_proposals_project (project_id, status, created_at, id),
            KEY idx_project_change_proposals_proposer (proposed_by_participant_id, created_at),
            KEY idx_project_change_proposals_target (target_agent_id),
            CONSTRAINT fk_project_change_proposals_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
            CONSTRAINT fk_project_change_proposals_proposer FOREIGN KEY (proposed_by_participant_id) REFERENCES project_participants(id),
            CONSTRAINT fk_project_change_proposals_target FOREIGN KEY (target_agent_id) REFERENCES chat_agents(id) ON DELETE SET NULL,
            CONSTRAINT fk_project_change_proposals_reviewer FOREIGN KEY (reviewed_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
            CONSTRAINT fk_project_change_proposals_applied_agent FOREIGN KEY (applied_agent_id) REFERENCES chat_agents(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ],
];
