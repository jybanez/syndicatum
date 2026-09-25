<?php

return [
    'version' => '202609250008',
    'description' => 'Make the Blank Project template context-free',
    'statements' => [
        "UPDATE project_templates
         SET description = NULL, instructions = NULL, version = version + 1, updated_at = UTC_TIMESTAMP()
         WHERE public_id = '00000000-0000-4000-8000-000000000101'
           AND (description IS NOT NULL OR instructions IS NOT NULL)",
    ],
];
