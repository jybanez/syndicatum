<?php

return [
    'version' => '202609250002',
    'description' => 'Categorize reusable project templates',
    'statements' => [
        "CREATE TABLE IF NOT EXISTS project_template_categories (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            slug VARCHAR(80) NOT NULL,
            name VARCHAR(120) NOT NULL,
            description VARCHAR(500) NULL,
            sort_order INT UNSIGNED NOT NULL DEFAULT 0,
            status ENUM('active','archived') NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_project_template_categories_slug (slug),
            KEY idx_project_template_categories_status_sort (status, sort_order, name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "INSERT INTO project_template_categories (slug, name, description, sort_order, status, created_at, updated_at) VALUES
            ('general', 'General', 'Flexible projects that do not fit a specialized workflow.', 10, 'active', UTC_TIMESTAMP(), UTC_TIMESTAMP()),
            ('software-development', 'Software Development', 'Application design, implementation, testing, and maintenance.', 20, 'active', UTC_TIMESTAMP(), UTC_TIMESTAMP()),
            ('operations', 'Operations', 'Operational delivery, incidents, recovery, and service management.', 30, 'active', UTC_TIMESTAMP(), UTC_TIMESTAMP()),
            ('research-decision', 'Research & Decision', 'Investigation, comparison, analysis, and recommendations.', 40, 'active', UTC_TIMESTAMP(), UTC_TIMESTAMP()),
            ('business-commercial', 'Business & Commercial', 'Commercial assessment, launch readiness, and business planning.', 50, 'active', UTC_TIMESTAMP(), UTC_TIMESTAMP()),
            ('documentation-content', 'Documentation & Content', 'Technical documentation, knowledge, and content production.', 60, 'active', UTC_TIMESTAMP(), UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description), sort_order = VALUES(sort_order), updated_at = VALUES(updated_at)",
        [
            'unless_column' => ['project_templates', 'category_id'],
            'sql' => "ALTER TABLE project_templates
                ADD COLUMN category_id BIGINT UNSIGNED NULL AFTER public_id,
                ADD KEY idx_project_templates_category_status_name (category_id, status, name),
                ADD CONSTRAINT fk_project_templates_category FOREIGN KEY (category_id) REFERENCES project_template_categories(id)"
        ],
        "UPDATE project_templates SET category_id = (SELECT id FROM project_template_categories WHERE slug = 'general' LIMIT 1) WHERE category_id IS NULL",
        "ALTER TABLE project_templates DROP FOREIGN KEY fk_project_templates_category",
        "ALTER TABLE project_templates MODIFY category_id BIGINT UNSIGNED NOT NULL",
        "ALTER TABLE project_templates ADD CONSTRAINT fk_project_templates_category FOREIGN KEY (category_id) REFERENCES project_template_categories(id)",
    ],
];
