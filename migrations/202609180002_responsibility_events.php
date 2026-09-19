<?php

return [
    'version' => '202609180002_responsibility_events',
    'description' => 'Preserve append-only responsibility evidence beside canonical messages',
    'statements' => [
        [
            'unless_column' => ['project_participants', 'status_generation'],
            'sql' => 'ALTER TABLE project_participants ADD status_generation BIGINT UNSIGNED NOT NULL DEFAULT 0',
        ],
        [
            'unless_column' => ['message_addressees', 'responsibility_status_generation'],
            'sql' => 'ALTER TABLE message_addressees ADD responsibility_status_generation BIGINT UNSIGNED NULL',
        ],
        "CREATE TABLE IF NOT EXISTS responsibility_events (
            event_message_id BIGINT UNSIGNED PRIMARY KEY,
            project_id BIGINT UNSIGNED NOT NULL,
            request_message_id BIGINT UNSIGNED NOT NULL,
            initial_responder_participant_id BIGINT UNSIGNED NOT NULL,
            actor_participant_id BIGINT UNSIGNED NOT NULL,
            actor_was_moderator TINYINT(1) NOT NULL DEFAULT 0,
            kind VARCHAR(40) NOT NULL,
            prior_state VARCHAR(24) NOT NULL,
            expected_event_message_id BIGINT UNSIGNED NOT NULL,
            reference_event_message_id BIGINT UNSIGNED NULL,
            target_participant_id BIGINT UNSIGNED NULL,
            responder_status_generation BIGINT UNSIGNED NULL,
            idempotency_key VARCHAR(160) NOT NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_responsibility_item (project_id, request_message_id,
                initial_responder_participant_id, event_message_id),
            UNIQUE KEY uq_responsibility_retry (project_id, actor_participant_id, idempotency_key),
            CONSTRAINT fk_responsibility_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
            CONSTRAINT fk_responsibility_request FOREIGN KEY (request_message_id) REFERENCES messages(id),
            CONSTRAINT fk_responsibility_message FOREIGN KEY (event_message_id) REFERENCES messages(id),
            CONSTRAINT fk_responsibility_responder FOREIGN KEY (initial_responder_participant_id) REFERENCES project_participants(id),
            CONSTRAINT fk_responsibility_actor FOREIGN KEY (actor_participant_id) REFERENCES project_participants(id),
            CONSTRAINT fk_responsibility_target FOREIGN KEY (target_participant_id) REFERENCES project_participants(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ],
];
