<?php

return [
    'version' => '202609280004',
    'description' => 'Add AI-proposed milestone and deliverable plans',
    'statements' => [
        "ALTER TABLE project_change_proposals
            MODIFY proposal_type ENUM('project_details','project_plan','agent_setup','agent_profile_update') NOT NULL,
            ADD COLUMN base_plan_fingerprint CHAR(64) NULL AFTER rationale",
    ],
];
