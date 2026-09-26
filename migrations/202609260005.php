<?php

return [
    'version' => '202609260005',
    'description' => 'Add FYI notification recipients for external integrations',
    'statements' => [
        "CREATE TABLE IF NOT EXISTS integration_notification_recipients (
            project_id BIGINT UNSIGNED NOT NULL,
            integration_id BIGINT UNSIGNED NOT NULL,
            participant_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (integration_id, participant_id),
            INDEX idx_integration_notification_recipient (project_id, participant_id),
            CONSTRAINT fk_integration_notification_project FOREIGN KEY (project_id)
                REFERENCES projects(id) ON DELETE CASCADE,
            CONSTRAINT fk_integration_notification_connection FOREIGN KEY (integration_id)
                REFERENCES integration_connections(id) ON DELETE CASCADE,
            CONSTRAINT fk_integration_notification_participant FOREIGN KEY (participant_id)
                REFERENCES project_participants(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "INSERT IGNORE INTO integration_notification_recipients
         (project_id, integration_id, participant_id, created_at)
         SELECT ic.project_id, ic.id, pp.id, UTC_TIMESTAMP()
         FROM integration_connections ic
         JOIN projects p ON p.id = ic.project_id
         JOIN project_participants pp ON pp.project_id = p.id
             AND pp.kind = 'human' AND pp.user_id = p.owner_user_id AND pp.status = 'active'
         WHERE ic.status <> 'removed'",
    ],
];
