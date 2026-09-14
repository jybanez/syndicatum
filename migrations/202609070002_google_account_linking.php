<?php

return [
    'version' => '202609070002_google_account_linking',
    'description' => 'Bind Google OAuth attempts to an authenticated Syndicatum account-linking flow',
    'statements' => [
        [
            'unless_column' => ['google_oauth_attempts', 'flow_type'],
            'sql' => "ALTER TABLE google_oauth_attempts ADD flow_type VARCHAR(20) NOT NULL DEFAULT 'login' AFTER code_verifier",
        ],
        [
            'unless_column' => ['google_oauth_attempts', 'link_user_id'],
            'sql' => 'ALTER TABLE google_oauth_attempts ADD link_user_id BIGINT UNSIGNED NULL AFTER flow_type, ADD INDEX idx_google_oauth_attempts_link_user (link_user_id)',
        ],
    ],
];
