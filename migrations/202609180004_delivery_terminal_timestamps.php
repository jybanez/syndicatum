<?php

return [
    'version' => '202609180004_delivery_terminal_timestamps',
    'description' => 'Record the actual terminal transition for agent delivery queues',
    'statements' => [
        [
            'unless_column' => ['agent_webhook_deliveries', 'terminal_at'],
            'sql' => 'ALTER TABLE agent_webhook_deliveries ADD terminal_at DATETIME NULL AFTER delivered_at',
        ],
        [
            'unless_column' => ['workspace_agent_trigger_deliveries', 'terminal_at'],
            'sql' => 'ALTER TABLE workspace_agent_trigger_deliveries ADD terminal_at DATETIME NULL AFTER delivered_at',
        ],
        [
            'unless_column' => ['responses_api_deliveries', 'terminal_at'],
            'sql' => 'ALTER TABLE responses_api_deliveries ADD terminal_at DATETIME NULL AFTER delivered_at',
        ],
        // Older rows have no exact transition timestamp. Prefer the last
        // attempt, then the disable-migration timestamp, and only then creation.
        "UPDATE agent_webhook_deliveries SET terminal_at = COALESCE(last_attempt_at, delivered_at, created_at) WHERE status = 'dead' AND terminal_at IS NULL",
        "UPDATE workspace_agent_trigger_deliveries SET terminal_at = COALESCE(last_attempt_at, delivered_at, created_at) WHERE status = 'dead' AND terminal_at IS NULL",
        "UPDATE responses_api_deliveries SET terminal_at = COALESCE(last_attempt_at, delivered_at, created_at) WHERE status = 'dead' AND terminal_at IS NULL",
        // The earlier disabled-path migration used delivered_at for a dead
        // transition. Preserve that time above, then stop reporting it as a
        // successful delivery.
        "UPDATE agent_webhook_deliveries SET delivered_at = NULL WHERE status = 'dead' AND delivered_at IS NOT NULL",
        "UPDATE workspace_agent_trigger_deliveries SET delivered_at = NULL WHERE status = 'dead' AND delivered_at IS NOT NULL",
        "UPDATE responses_api_deliveries SET delivered_at = NULL WHERE status = 'dead' AND delivered_at IS NOT NULL",
    ],
];
