<?php

return [
    'version' => '202609130001_disable_chatgpt_proactive_activation',
    'description' => 'Disable ChatGPT Responses API and Workspace Agent proactive activation',
    'statements' => [
        "UPDATE agent_activation_bindings
         SET enabled = 0, updated_at = UTC_TIMESTAMP()
         WHERE runtime_type = 'chatgpt' AND enabled <> 0",
        "UPDATE responses_api_deliveries
         SET status = 'dead', delivered_at = UTC_TIMESTAMP(),
             last_error = 'ChatGPT Responses API activation disabled by product policy.'
         WHERE status IN ('queued', 'sending', 'waiting', 'retry')",
        "UPDATE workspace_agent_trigger_deliveries
         SET status = 'dead', delivered_at = UTC_TIMESTAMP(),
             last_error = 'ChatGPT Workspace Agent activation disabled by product policy.'
         WHERE status IN ('queued', 'sending', 'retry')",
    ],
];
