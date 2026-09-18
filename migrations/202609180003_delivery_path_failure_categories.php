<?php

return [
    'version' => '202609180003_delivery_path_failure_categories',
    'description' => 'Record bounded failure categories and attempt timing for agent delivery paths',
    'statements' => [
        [
            'unless_column' => ['agent_webhook_deliveries', 'last_attempt_at'],
            'sql' => 'ALTER TABLE agent_webhook_deliveries ADD last_attempt_at DATETIME NULL AFTER attempt_count',
        ],
        [
            'unless_column' => ['agent_webhook_deliveries', 'last_failure_code'],
            'sql' => 'ALTER TABLE agent_webhook_deliveries ADD last_failure_code VARCHAR(32) NULL AFTER last_error',
        ],
        [
            'unless_column' => ['workspace_agent_trigger_deliveries', 'last_attempt_at'],
            'sql' => 'ALTER TABLE workspace_agent_trigger_deliveries ADD last_attempt_at DATETIME NULL AFTER attempt_count',
        ],
        [
            'unless_column' => ['workspace_agent_trigger_deliveries', 'last_failure_code'],
            'sql' => 'ALTER TABLE workspace_agent_trigger_deliveries ADD last_failure_code VARCHAR(32) NULL AFTER last_error',
        ],
        [
            'unless_column' => ['responses_api_deliveries', 'last_attempt_at'],
            'sql' => 'ALTER TABLE responses_api_deliveries ADD last_attempt_at DATETIME NULL AFTER attempt_count',
        ],
        [
            'unless_column' => ['responses_api_deliveries', 'last_failure_code'],
            'sql' => 'ALTER TABLE responses_api_deliveries ADD last_failure_code VARCHAR(32) NULL AFTER last_error',
        ],
    ],
];
