<?php

return [
    'version' => '202609070001_google_sso',
    'description' => 'Add Google identities, OAuth attempts, and explicit session authentication providers',
    'statements' => [
        [
            'unless_column' => ['users', 'google_subject'],
            'sql' => 'ALTER TABLE users ADD google_subject VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL UNIQUE AFTER pbb_user_id',
        ],
        [
            'unless_column' => ['syndicatum_sessions', 'auth_provider'],
            'sql' => "ALTER TABLE syndicatum_sessions ADD auth_provider VARCHAR(40) NOT NULL DEFAULT 'native' AFTER account_session_id",
        ],
        "UPDATE syndicatum_sessions SET auth_provider = 'account' WHERE account_session_id IS NOT NULL AND auth_provider = 'native'",
        "CREATE TABLE IF NOT EXISTS google_oauth_attempts (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            attempt_hash CHAR(64) NOT NULL UNIQUE,
            state_hash CHAR(64) NOT NULL UNIQUE,
            nonce VARCHAR(128) NOT NULL,
            code_verifier VARCHAR(128) NOT NULL,
            return_path VARCHAR(500) NOT NULL DEFAULT '/',
            created_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            consumed_at DATETIME NULL,
            INDEX idx_google_oauth_attempts_expiry (expires_at, consumed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ],
];
