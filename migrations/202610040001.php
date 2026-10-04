<?php

return [
    'version' => '202610040001',
    'description' => 'Add canonical project file attachments to timeline messages',
    'statements' => [
        "CREATE TABLE IF NOT EXISTS message_file_attachments (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            project_id BIGINT UNSIGNED NOT NULL,
            message_id BIGINT UNSIGNED NOT NULL,
            project_file_id BIGINT UNSIGNED NOT NULL,
            attached_by_participant_id BIGINT UNSIGNED NOT NULL,
            position SMALLINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_message_file_attachment (message_id, project_file_id),
            UNIQUE KEY uq_message_file_position (message_id, position),
            KEY idx_message_file_attachments_project_file (project_id, project_file_id, message_id),
            CONSTRAINT fk_message_file_attachments_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
            CONSTRAINT fk_message_file_attachments_message FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE,
            CONSTRAINT fk_message_file_attachments_file FOREIGN KEY (project_file_id) REFERENCES project_files(id),
            CONSTRAINT fk_message_file_attachments_actor FOREIGN KEY (attached_by_participant_id) REFERENCES project_participants(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ],
];
