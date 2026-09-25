<?php

return [
    'version' => '202609240001',
    'description' => 'Add versioned project context and project-scoped agent role assignments',
    'statements' => [
        [
            'unless_column' => ['projects', 'context_version'],
            'sql' => 'ALTER TABLE projects ADD context_version BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER instructions',
        ],
        [
            'unless_column' => ['project_agents', 'role_title'],
            'sql' => 'ALTER TABLE project_agents ADD role_title VARCHAR(120) NULL AFTER display_name',
        ],
        [
            'unless_column' => ['project_agents', 'role_summary'],
            'sql' => 'ALTER TABLE project_agents ADD role_summary TEXT NULL AFTER role_title',
        ],
        [
            'unless_column' => ['project_agents', 'role_instructions'],
            'sql' => 'ALTER TABLE project_agents ADD role_instructions MEDIUMTEXT NULL AFTER role_summary',
        ],
        [
            'unless_column' => ['project_agents', 'role_version'],
            'sql' => 'ALTER TABLE project_agents ADD role_version BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER role_instructions',
        ],
        [
            'unless_column' => ['project_agents', 'supervising_participant_id'],
            'sql' => 'ALTER TABLE project_agents'
                . ' ADD supervising_participant_id BIGINT UNSIGNED NULL AFTER role_version,'
                . ' ADD INDEX idx_project_agents_supervisor (project_id, supervising_participant_id)',
        ],
        'UPDATE project_agents pa JOIN chat_agents a ON a.id = pa.agent_id
         SET pa.role_summary = NULLIF(TRIM(a.description), \'\')
         WHERE pa.role_summary IS NULL AND a.description IS NOT NULL',
    ],
];
