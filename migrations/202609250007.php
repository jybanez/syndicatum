<?php

return [
    'version' => '202609250007',
    'description' => 'Simplify the blank project template name',
    'statements' => [
        "UPDATE project_templates
         SET name = 'Blank Project', version = version + 1, updated_at = UTC_TIMESTAMP()
         WHERE public_id = '00000000-0000-4000-8000-000000000101'
           AND name <> 'Blank Project'",
    ],
];
