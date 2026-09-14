<?php

return [
    'version' => '202609140003_chatgpt_account_oauth',
    'description' => 'Make ChatGPT OAuth account-scoped; discussion binding selects project and agent',
    'statements' => [
        'ALTER TABLE oauth_authorization_codes MODIFY project_id BIGINT UNSIGNED NULL, MODIFY agent_id BIGINT UNSIGNED NULL',
        'ALTER TABLE oauth_access_tokens MODIFY project_id BIGINT UNSIGNED NULL, MODIFY agent_id BIGINT UNSIGNED NULL',
        'ALTER TABLE oauth_refresh_tokens MODIFY project_id BIGINT UNSIGNED NULL, MODIFY agent_id BIGINT UNSIGNED NULL',
    ],
];
