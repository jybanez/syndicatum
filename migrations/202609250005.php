<?php

return [
    'version' => '202609250005',
    'description' => 'Add small-business administration, event, marketing, launch, and onboarding templates',
    'statements' => [
        "INSERT INTO project_templates
            (public_id, category_id, name, description, instructions, origin, status, version, created_by_user_id, updated_by_user_id, created_at, updated_at)
         VALUES
            ('00000000-0000-4000-8000-000000000301', (SELECT id FROM project_template_categories WHERE slug = 'operations'), 'Administrative Process Improvement',
             'Simplify a recurring administrative process, clarify ownership, and introduce a practical improved workflow.',
             'Define the current process, users, inputs, outputs, timing, costs, pain points, and approval authority before proposing changes. Keep the future process proportionate to the business, preserve required records and controls, assign clear owners, test the workflow with realistic cases, document the change, and review whether it produced the expected improvement.',
             'system', 'active', 1, NULL, NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
            ('00000000-0000-4000-8000-000000000302', (SELECT id FROM project_template_categories WHERE slug = 'operations'), 'Event Planning and Delivery',
             'Plan and deliver a business event with clear goals, budget, logistics, communications, and follow-up.',
             'Define the event purpose, audience, success measures, budget authority, date, venue or platform, and decision owner. Maintain one schedule covering suppliers, guests, materials, staffing, safety, communications, and contingencies. Confirm critical arrangements before commitments, protect attendee information, verify readiness before launch, and complete a short post-event review.',
             'system', 'active', 1, NULL, NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
            ('00000000-0000-4000-8000-000000000303', (SELECT id FROM project_template_categories WHERE slug = 'business-commercial'), 'Marketing Campaign',
             'Plan, produce, launch, and evaluate a focused marketing campaign across one or more channels.',
             'Define the campaign objective, audience, offer, message, channels, budget, schedule, brand rules, approval owner, and measurable success criteria before production. Ensure claims are supportable, protect customer data and consent, keep channel assets consistent, verify links and tracking before launch, monitor results against the agreed measures, and record lessons for the next campaign.',
             'system', 'active', 1, NULL, NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
            ('00000000-0000-4000-8000-000000000304', (SELECT id FROM project_template_categories WHERE slug = 'business-commercial'), 'Product or Service Launch',
             'Coordinate the operational and commercial work required to introduce a product, service, or major offer.',
             'Define the offer, target customer, launch scope, price or commercial terms, date, decision authority, readiness criteria, and success measures. Coordinate product capability, operations, staff preparation, marketing, sales, support, documentation, and customer commitments. Separate verified readiness from planned work, stop on material launch blockers, and review outcomes after launch.',
             'system', 'active', 1, NULL, NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
            ('00000000-0000-4000-8000-000000000305', (SELECT id FROM project_template_categories WHERE slug = 'operations'), 'Employee Hiring and Onboarding',
             'Run a consistent hiring and onboarding process for a defined role while protecting candidate and employee information.',
             'Confirm the approved role, responsibilities, budget, selection criteria, decision makers, timeline, and applicable company or legal requirements before recruiting. Use consistent job-related evaluation criteria, restrict access to personal information, document decisions appropriately, avoid unsupported or discriminatory criteria, prepare the workplace and access plan, and verify onboarding completion with the new employee and manager.',
             'system', 'active', 1, NULL, NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE category_id = VALUES(category_id), name = VALUES(name), description = VALUES(description), instructions = VALUES(instructions), origin = 'system', status = 'active', updated_at = VALUES(updated_at)",
        "INSERT INTO project_template_agents
            (template_id, preset_key, display_name, provider, role_title, role_summary, role_instructions, supervising_agent_id, sort_order, created_at, updated_at)
         VALUES
            ((SELECT id FROM project_templates WHERE public_id = '00000000-0000-4000-8000-000000000301'), 'process-coordinator', 'Process Coordinator', 'unassigned', 'Process Coordinator',
             'Owns the improvement goal, participants, approvals, schedule, and adoption of the new process.',
             'Confirm the process boundary and owner, coordinate affected staff, keep decisions and actions visible, obtain required approvals, and ensure the improved workflow is introduced with clear ownership and support.', NULL, 10, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
            ((SELECT id FROM project_templates WHERE public_id = '00000000-0000-4000-8000-000000000301'), 'workflow-analyst', 'Workflow Analyst', 'unassigned', 'Workflow Analyst',
             'Maps the current workflow, identifies avoidable effort and risk, and designs a practical future process.',
             'Observe the real process, document handoffs and exceptions, quantify delay or rework where possible, preserve required controls and records, and propose changes that fit the business capacity.', NULL, 20, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
            ((SELECT id FROM project_templates WHERE public_id = '00000000-0000-4000-8000-000000000301'), 'process-reviewer', 'Process Reviewer', 'unassigned', 'Process Quality Reviewer',
             'Checks that the proposed process is usable, controlled, documented, and measurably better.',
             'Test realistic normal and exception cases, confirm responsibilities and approvals are clear, identify missing controls or unnecessary complexity, and verify the agreed improvement measures after adoption.', NULL, 30, UTC_TIMESTAMP(), UTC_TIMESTAMP()),

            ((SELECT id FROM project_templates WHERE public_id = '00000000-0000-4000-8000-000000000302'), 'event-coordinator', 'Event Coordinator', 'unassigned', 'Event Coordinator',
             'Owns the event outcome, budget, schedule, approvals, suppliers, and overall delivery coordination.',
             'Maintain the event brief and master schedule, coordinate decisions and owners, confirm commitments against budget and authority, manage contingencies, and lead readiness and post-event reviews.', NULL, 10, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
            ((SELECT id FROM project_templates WHERE public_id = '00000000-0000-4000-8000-000000000302'), 'logistics-coordinator', 'Logistics Coordinator', 'unassigned', 'Logistics Coordinator',
             'Coordinates venue or platform, suppliers, equipment, staffing, materials, safety, and event-day operations.',
             'Track logistical requirements and dependencies, confirm suppliers and access, protect attendee data, prepare fallback arrangements, and provide clear operating information to everyone working the event.', NULL, 20, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
            ((SELECT id FROM project_templates WHERE public_id = '00000000-0000-4000-8000-000000000302'), 'event-communications-lead', 'Communications Lead', 'unassigned', 'Event Communications Lead',
             'Manages invitations, attendee information, promotional messages, reminders, and follow-up communications.',
             'Use the approved audience and message, keep information accurate and consistent, verify links and contact paths, respect consent and privacy, and measure communication response and attendee feedback.', NULL, 30, UTC_TIMESTAMP(), UTC_TIMESTAMP()),

            ((SELECT id FROM project_templates WHERE public_id = '00000000-0000-4000-8000-000000000303'), 'campaign-manager', 'Campaign Manager', 'unassigned', 'Campaign Manager',
             'Owns campaign objectives, audience, budget, schedule, channels, approvals, and performance decisions.',
             'Maintain the campaign brief, align contributors around measurable outcomes, protect spending and approval boundaries, coordinate launch timing, and adjust only from reliable performance evidence.', NULL, 10, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
            ((SELECT id FROM project_templates WHERE public_id = '00000000-0000-4000-8000-000000000303'), 'campaign-content-creator', 'Content Creator', 'unassigned', 'Campaign Content Creator',
             'Produces channel-appropriate campaign copy and creative assets from the approved message and offer.',
             'Follow brand, audience, channel, and factual requirements; keep claims supportable; produce accessible assets; use approved calls to action; and resolve review feedback before launch.', NULL, 20, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
            ((SELECT id FROM project_templates WHERE public_id = '00000000-0000-4000-8000-000000000303'), 'campaign-performance-reviewer', 'Performance Reviewer', 'unassigned', 'Campaign Performance Reviewer',
             'Validates launch readiness, tracking, results, and evidence-based improvement recommendations.',
             'Check links, targeting, consent, tracking, and asset consistency before launch. Compare results with the agreed measures, distinguish signal from assumption, and document practical lessons and next actions.', NULL, 30, UTC_TIMESTAMP(), UTC_TIMESTAMP()),

            ((SELECT id FROM project_templates WHERE public_id = '00000000-0000-4000-8000-000000000304'), 'launch-coordinator', 'Launch Coordinator', 'unassigned', 'Launch Coordinator',
             'Coordinates the launch plan, readiness gates, owners, dependencies, decisions, and launch-day execution.',
             'Maintain one readiness plan covering the offer, operations, sales, marketing, support, documentation, and customer commitments. Escalate blockers and prevent launch when required evidence or approvals are missing.', NULL, 10, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
            ((SELECT id FROM project_templates WHERE public_id = '00000000-0000-4000-8000-000000000304'), 'launch-marketing-lead', 'Marketing Lead', 'unassigned', 'Launch Marketing Lead',
             'Prepares the launch audience, positioning, campaign assets, channels, and customer communications.',
             'Translate verified offer capabilities into clear customer-facing messages, align channels and timing, avoid unsupported claims, confirm tracking and response paths, and report launch engagement evidence.', NULL, 20, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
            ((SELECT id FROM project_templates WHERE public_id = '00000000-0000-4000-8000-000000000304'), 'launch-readiness-reviewer', 'Readiness Reviewer', 'unassigned', 'Launch Readiness Reviewer',
             'Independently checks that product, operations, people, support, and communications meet launch criteria.',
             'Review readiness evidence against the agreed gate, test critical customer journeys where practical, identify unsupported commitments and unresolved dependencies, and give a clear go, conditional-go, or no-go recommendation.', NULL, 30, UTC_TIMESTAMP(), UTC_TIMESTAMP()),

            ((SELECT id FROM project_templates WHERE public_id = '00000000-0000-4000-8000-000000000305'), 'hiring-coordinator', 'Hiring Coordinator', 'unassigned', 'Hiring Coordinator',
             'Owns the approved role, hiring schedule, candidate process, communications, records, and decision coordination.',
             'Keep the role and selection criteria job-related, coordinate consistent candidate stages, restrict personal information to authorized participants, document required decisions, and maintain respectful timely communications.', NULL, 10, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
            ((SELECT id FROM project_templates WHERE public_id = '00000000-0000-4000-8000-000000000305'), 'candidate-evaluator', 'Candidate Evaluator', 'unassigned', 'Candidate Evaluator',
             'Evaluates candidates consistently against the approved responsibilities and selection criteria.',
             'Use the same job-related evidence standard for candidates, record concise observations, avoid irrelevant personal assumptions, protect confidential information, and distinguish demonstrated capability from inference.', NULL, 20, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
            ((SELECT id FROM project_templates WHERE public_id = '00000000-0000-4000-8000-000000000305'), 'onboarding-coordinator', 'Onboarding Coordinator', 'unassigned', 'Onboarding Coordinator',
             'Prepares the new employee, manager, workplace, access, training, documentation, and early check-ins.',
             'Confirm the accepted offer and start plan, arrange only approved access and equipment, provide required policies and role guidance, coordinate introductions and training, and verify completion and outstanding needs with the employee and manager.', NULL, 30, UTC_TIMESTAMP(), UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE display_name = VALUES(display_name), provider = VALUES(provider), role_title = VALUES(role_title), role_summary = VALUES(role_summary), role_instructions = VALUES(role_instructions), sort_order = VALUES(sort_order), updated_at = VALUES(updated_at)",
    ],
];
