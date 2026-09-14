<?php

return [
    'version' => '202609120001_project_public_ids',
    'description' => 'Add immutable UUID public identifiers for project routes',
    'statements' => [
        [
            'unless_column' => ['projects', 'public_id'],
            'sql' => "ALTER TABLE projects ADD COLUMN public_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER id",
        ],
        "UPDATE projects SET public_id = LEFT(SHA2(CONCAT(UUID(), RANDOM_BYTES(32), id), 256), 32) WHERE public_id IS NULL OR public_id = ''",
        "UPDATE projects SET public_id = LOWER(CONCAT(SUBSTRING(public_id, 1, 8), '-', SUBSTRING(public_id, 9, 4), '-4', SUBSTRING(public_id, 14, 3), '-', ELT(1 + FLOOR(RAND() * 4), '8', '9', 'a', 'b'), SUBSTRING(public_id, 18, 3), '-', SUBSTRING(public_id, 21, 12))) WHERE public_id NOT LIKE '________-____-____-____-____________'",
        "ALTER TABLE projects MODIFY public_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL",
        "ALTER TABLE projects ADD UNIQUE KEY uq_projects_public_id (public_id)",
    ],
];
