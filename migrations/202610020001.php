<?php

return [
    'version' => '202610020001',
    'description' => 'Add optional Google Drive shared storage to projects',
    'statements' => [
        "ALTER TABLE projects ADD COLUMN google_drive_url VARCHAR(500) NULL AFTER instructions",
    ],
];
