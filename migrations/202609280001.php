<?php

return [
    'version' => '202609280001',
    'description' => 'Add bounded project status activity indexes',
    'statements' => [
        'ALTER TABLE messages ADD INDEX idx_messages_project_created (project_id, created_at, id)',
        'ALTER TABLE project_tasks ADD INDEX idx_project_tasks_project_created (project_id, created_at, id)',
        'ALTER TABLE project_tasks ADD INDEX idx_project_tasks_project_completed (project_id, completed_at, id)',
    ],
];
