<?php

return [
    'version' => '202609250006',
    'description' => 'Record project template provenance',
    'statements' => [
        [
            'unless_column' => ['projects', 'source_template_public_id'],
            'sql' => "ALTER TABLE projects
                ADD COLUMN source_template_public_id CHAR(36) NULL AFTER instructions,
                ADD COLUMN source_template_name VARCHAR(160) NULL AFTER source_template_public_id,
                ADD COLUMN source_template_version BIGINT UNSIGNED NULL AFTER source_template_name,
                ADD KEY idx_projects_source_template (source_template_public_id)"
        ],
    ],
];
