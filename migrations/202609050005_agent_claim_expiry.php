<?php

return [
    'version' => '202609050005_agent_claim_expiry',
    'description' => 'Expire unused agent claim codes',
    'statements' => [
        [
            'unless_column' => ['chat_agents', 'claim_expires_at'],
            'sql' => 'ALTER TABLE chat_agents ADD claim_expires_at DATETIME NULL AFTER claim_hash',
        ],
    ],
];
