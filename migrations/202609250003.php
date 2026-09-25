<?php

return [
    'version' => '202609250003',
    'description' => 'Seed protected built-in project templates',
    'statements' => [
        [
            'unless_column' => ['project_templates', 'origin'],
            'sql' => "ALTER TABLE project_templates
                ADD COLUMN origin ENUM('system','custom') NOT NULL DEFAULT 'custom' AFTER instructions,
                MODIFY created_by_user_id BIGINT UNSIGNED NULL,
                MODIFY updated_by_user_id BIGINT UNSIGNED NULL"
        ],
        [
            'unless_column' => ['project_template_agents', 'preset_key'],
            'sql' => "ALTER TABLE project_template_agents
                ADD COLUMN preset_key VARCHAR(80) NULL AFTER template_id,
                ADD UNIQUE KEY uq_project_template_agents_key (template_id, preset_key)"
        ],
        "INSERT INTO project_templates
            (public_id, category_id, name, description, instructions, origin, status, version, created_by_user_id, updated_by_user_id, created_at, updated_at)
         SELECT '00000000-0000-4000-8000-000000000101', id, 'Blank Governed Project',
            'Start from a clean project while retaining standard authority, safety, coordination, and reporting rules.',
            'The project owner sets priorities and makes final decisions. Participants must work within their assigned roles and authorization boundaries, preserve security and auditability, validate material work before reporting completion, and escalate unclear, conflicting, destructive, or irreversible decisions to the project owner.',
            'system', 'active', 1, NULL, NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP()
         FROM project_template_categories WHERE slug = 'general'
         ON DUPLICATE KEY UPDATE category_id = VALUES(category_id), name = VALUES(name), description = VALUES(description), instructions = VALUES(instructions), origin = 'system', status = 'active', updated_at = VALUES(updated_at)",
        "INSERT INTO project_templates
            (public_id, category_id, name, description, instructions, origin, status, version, created_by_user_id, updated_by_user_id, created_at, updated_at)
         SELECT '00000000-0000-4000-8000-000000000102', id, 'Software Product Delivery',
            'Design, implement, test, review, and prepare a software product or feature for release.',
            'Confirm the requested outcome and acceptance criteria before implementation. Inspect the existing architecture before changing code. Keep changes scoped, preserve compatibility and data, validate authorization and security boundaries, add proportionate tests, and require independent review before completion. Record material decisions, limitations, verification evidence, and follow-up work.',
            'system', 'active', 1, NULL, NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP()
         FROM project_template_categories WHERE slug = 'software-development'
         ON DUPLICATE KEY UPDATE category_id = VALUES(category_id), name = VALUES(name), description = VALUES(description), instructions = VALUES(instructions), origin = 'system', status = 'active', updated_at = VALUES(updated_at)",
        "INSERT INTO project_templates
            (public_id, category_id, name, description, instructions, origin, status, version, created_by_user_id, updated_by_user_id, created_at, updated_at)
         SELECT '00000000-0000-4000-8000-000000000103', id, 'Bug Investigation and Resolution',
            'Trace a defect to its underlying cause, implement a focused correction, and verify the result against regressions.',
            'Reproduce and document the observed behavior before changing code. Separate symptoms from the underlying cause, preserve relevant evidence, and identify affected boundaries. Keep the correction focused, add regression coverage, verify adjacent behavior, and clearly distinguish diagnosis, implementation, verification, and any remaining uncertainty.',
            'system', 'active', 1, NULL, NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP()
         FROM project_template_categories WHERE slug = 'software-development'
         ON DUPLICATE KEY UPDATE category_id = VALUES(category_id), name = VALUES(name), description = VALUES(description), instructions = VALUES(instructions), origin = 'system', status = 'active', updated_at = VALUES(updated_at)",
        "INSERT INTO project_templates
            (public_id, category_id, name, description, instructions, origin, status, version, created_by_user_id, updated_by_user_id, created_at, updated_at)
         SELECT '00000000-0000-4000-8000-000000000104', id, 'Research and Recommendation',
            'Investigate a question, compare credible evidence, and produce a defensible recommendation with explicit tradeoffs.',
            'Define the decision question, scope, evaluation criteria, and evidence requirements before research. Prefer authoritative sources, distinguish facts from inference, surface uncertainty and conflicting evidence, compare viable alternatives consistently, and present a recommendation with rationale, risks, tradeoffs, and conditions that would change the conclusion.',
            'system', 'active', 1, NULL, NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP()
         FROM project_template_categories WHERE slug = 'research-decision'
         ON DUPLICATE KEY UPDATE category_id = VALUES(category_id), name = VALUES(name), description = VALUES(description), instructions = VALUES(instructions), origin = 'system', status = 'active', updated_at = VALUES(updated_at)",
        "INSERT INTO project_template_agents
            (template_id, preset_key, display_name, provider, role_title, role_summary, role_instructions, supervising_agent_id, sort_order, created_at, updated_at)
         SELECT id, 'lead-developer', 'Lead Developer', 'unassigned', 'Lead Developer',
            'Owns technical implementation and coordinates delivery of the approved software outcome.',
            'Inspect the current implementation, propose scoped changes, implement approved work, preserve compatibility and security boundaries, add tests, verify results, and escalate material architectural or product decisions.',
            NULL, 10, UTC_TIMESTAMP(), UTC_TIMESTAMP() FROM project_templates WHERE public_id = '00000000-0000-4000-8000-000000000102'
         ON DUPLICATE KEY UPDATE display_name = VALUES(display_name), provider = VALUES(provider), role_title = VALUES(role_title), role_summary = VALUES(role_summary), role_instructions = VALUES(role_instructions), sort_order = VALUES(sort_order), updated_at = VALUES(updated_at)",
        "INSERT INTO project_template_agents
            (template_id, preset_key, display_name, provider, role_title, role_summary, role_instructions, supervising_agent_id, sort_order, created_at, updated_at)
         SELECT id, 'code-reviewer', 'Code Reviewer', 'unassigned', 'Code Reviewer',
            'Independently reviews implementation quality, correctness, maintainability, and compatibility.',
            'Review the actual change and relevant surrounding code. Identify concrete defects and risks, prioritize actionable findings, verify claimed fixes, and avoid taking over implementation unless explicitly assigned.',
            NULL, 20, UTC_TIMESTAMP(), UTC_TIMESTAMP() FROM project_templates WHERE public_id = '00000000-0000-4000-8000-000000000102'
         ON DUPLICATE KEY UPDATE display_name = VALUES(display_name), provider = VALUES(provider), role_title = VALUES(role_title), role_summary = VALUES(role_summary), role_instructions = VALUES(role_instructions), sort_order = VALUES(sort_order), updated_at = VALUES(updated_at)",
        "INSERT INTO project_template_agents
            (template_id, preset_key, display_name, provider, role_title, role_summary, role_instructions, supervising_agent_id, sort_order, created_at, updated_at)
         SELECT id, 'qa-security-reviewer', 'QA and Security Reviewer', 'unassigned', 'QA and Security Reviewer',
            'Validates acceptance criteria, regression risk, authorization boundaries, and security-sensitive behavior.',
            'Build a risk-based verification plan, test critical and adjacent workflows, inspect authorization and data handling, record reproducible evidence, and report remaining limitations before release approval.',
            NULL, 30, UTC_TIMESTAMP(), UTC_TIMESTAMP() FROM project_templates WHERE public_id = '00000000-0000-4000-8000-000000000102'
         ON DUPLICATE KEY UPDATE display_name = VALUES(display_name), provider = VALUES(provider), role_title = VALUES(role_title), role_summary = VALUES(role_summary), role_instructions = VALUES(role_instructions), sort_order = VALUES(sort_order), updated_at = VALUES(updated_at)",
        "INSERT INTO project_template_agents
            (template_id, preset_key, display_name, provider, role_title, role_summary, role_instructions, supervising_agent_id, sort_order, created_at, updated_at)
         SELECT id, 'investigator', 'Investigator', 'unassigned', 'Defect Investigator',
            'Reproduces the defect, preserves evidence, and identifies the underlying cause and affected boundaries.',
            'Reproduce the issue before proposing a fix. Trace data and control flow, test competing hypotheses, document the root cause and scope, and hand off actionable evidence without changing production behavior unless explicitly assigned.',
            NULL, 10, UTC_TIMESTAMP(), UTC_TIMESTAMP() FROM project_templates WHERE public_id = '00000000-0000-4000-8000-000000000103'
         ON DUPLICATE KEY UPDATE display_name = VALUES(display_name), provider = VALUES(provider), role_title = VALUES(role_title), role_summary = VALUES(role_summary), role_instructions = VALUES(role_instructions), sort_order = VALUES(sort_order), updated_at = VALUES(updated_at)",
        "INSERT INTO project_template_agents
            (template_id, preset_key, display_name, provider, role_title, role_summary, role_instructions, supervising_agent_id, sort_order, created_at, updated_at)
         SELECT id, 'implementer', 'Implementer', 'unassigned', 'Fix Implementer',
            'Implements the smallest complete correction supported by the investigation evidence.',
            'Use the confirmed diagnosis, preserve unrelated behavior, add regression coverage, document meaningful tradeoffs, and verify the focused correction before submitting it for independent review.',
            NULL, 20, UTC_TIMESTAMP(), UTC_TIMESTAMP() FROM project_templates WHERE public_id = '00000000-0000-4000-8000-000000000103'
         ON DUPLICATE KEY UPDATE display_name = VALUES(display_name), provider = VALUES(provider), role_title = VALUES(role_title), role_summary = VALUES(role_summary), role_instructions = VALUES(role_instructions), sort_order = VALUES(sort_order), updated_at = VALUES(updated_at)",
        "INSERT INTO project_template_agents
            (template_id, preset_key, display_name, provider, role_title, role_summary, role_instructions, supervising_agent_id, sort_order, created_at, updated_at)
         SELECT id, 'verification-reviewer', 'Verification Reviewer', 'unassigned', 'Verification Reviewer',
            'Independently confirms the correction and checks for regressions and unsupported completion claims.',
            'Reproduce the original failure where safe, execute the regression evidence, inspect adjacent risk areas, and report whether acceptance criteria are satisfied without relying solely on the implementer''s summary.',
            NULL, 30, UTC_TIMESTAMP(), UTC_TIMESTAMP() FROM project_templates WHERE public_id = '00000000-0000-4000-8000-000000000103'
         ON DUPLICATE KEY UPDATE display_name = VALUES(display_name), provider = VALUES(provider), role_title = VALUES(role_title), role_summary = VALUES(role_summary), role_instructions = VALUES(role_instructions), sort_order = VALUES(sort_order), updated_at = VALUES(updated_at)",
        "INSERT INTO project_template_agents
            (template_id, preset_key, display_name, provider, role_title, role_summary, role_instructions, supervising_agent_id, sort_order, created_at, updated_at)
         SELECT id, 'researcher', 'Researcher', 'unassigned', 'Researcher',
            'Collects authoritative evidence and documents relevant facts, alternatives, and uncertainty.',
            'Work from the defined question and criteria, prefer primary sources, cite evidence precisely, distinguish observation from inference, and surface gaps or conflicting information.',
            NULL, 10, UTC_TIMESTAMP(), UTC_TIMESTAMP() FROM project_templates WHERE public_id = '00000000-0000-4000-8000-000000000104'
         ON DUPLICATE KEY UPDATE display_name = VALUES(display_name), provider = VALUES(provider), role_title = VALUES(role_title), role_summary = VALUES(role_summary), role_instructions = VALUES(role_instructions), sort_order = VALUES(sort_order), updated_at = VALUES(updated_at)",
        "INSERT INTO project_template_agents
            (template_id, preset_key, display_name, provider, role_title, role_summary, role_instructions, supervising_agent_id, sort_order, created_at, updated_at)
         SELECT id, 'critical-reviewer', 'Critical Reviewer', 'unassigned', 'Critical Reviewer',
            'Challenges evidence quality, assumptions, omissions, and inconsistent comparisons.',
            'Review the research independently, test whether sources support each claim, identify missing alternatives and decision risks, and provide concise corrective feedback without forcing a predetermined conclusion.',
            NULL, 20, UTC_TIMESTAMP(), UTC_TIMESTAMP() FROM project_templates WHERE public_id = '00000000-0000-4000-8000-000000000104'
         ON DUPLICATE KEY UPDATE display_name = VALUES(display_name), provider = VALUES(provider), role_title = VALUES(role_title), role_summary = VALUES(role_summary), role_instructions = VALUES(role_instructions), sort_order = VALUES(sort_order), updated_at = VALUES(updated_at)",
        "INSERT INTO project_template_agents
            (template_id, preset_key, display_name, provider, role_title, role_summary, role_instructions, supervising_agent_id, sort_order, created_at, updated_at)
         SELECT id, 'decision-synthesizer', 'Decision Synthesizer', 'unassigned', 'Decision Synthesizer',
            'Turns reviewed evidence into a clear recommendation with tradeoffs and decision conditions.',
            'Synthesize rather than invent evidence. Compare viable alternatives against the agreed criteria, state uncertainty, explain the recommendation and its risks, and identify conditions that would change the conclusion.',
            NULL, 30, UTC_TIMESTAMP(), UTC_TIMESTAMP() FROM project_templates WHERE public_id = '00000000-0000-4000-8000-000000000104'
         ON DUPLICATE KEY UPDATE display_name = VALUES(display_name), provider = VALUES(provider), role_title = VALUES(role_title), role_summary = VALUES(role_summary), role_instructions = VALUES(role_instructions), sort_order = VALUES(sort_order), updated_at = VALUES(updated_at)",
    ],
];
