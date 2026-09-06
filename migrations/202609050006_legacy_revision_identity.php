<?php

return [
    'version' => '202609050006_legacy_revision_identity',
    'description' => 'Preserve legacy revision identity during backfill',
    'statements' => [
        [
            'unless_column' => ['message_revisions', 'legacy_revision_id'],
            'sql' => 'ALTER TABLE message_revisions ADD legacy_revision_id BIGINT UNSIGNED NULL UNIQUE AFTER id',
        ],
    ],
];
