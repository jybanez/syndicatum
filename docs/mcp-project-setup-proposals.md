# MCP project and agent setup proposals

Syndicatum agents can help improve project details, project plans, and agent setup without receiving authority to change project governance directly. The MCP exposes four proposal tools:

- `propose_project_details` suggests a project name, description, or operating instructions.
- `propose_project_plan` suggests a bounded, create-only milestone and deliverable hierarchy.
- `propose_agent_setup` suggests a new project-scoped agent profile.
- `propose_agent_profile_update` suggests changes to an existing agent profile or supervisor assignment.

Every tool creates a durable, auditable proposal with `pending` status. A human project owner or administrator opens the timeline column's **AI Proposals** tab (or uses **Project actions → AI proposals** as a shortcut), chooses **Review**, and explicitly selects **Approve and apply** or **Reject**. Approval and the underlying project change are committed in one database transaction.

New proposals and review decisions update an already-open **AI Proposals** tab through Realtime without a browser refresh. The proposal-list event is only a content-free invalidation containing proposal identity, version, status, and change type; authorized owners and administrators then reload the protected proposal resource. A successful approval or rejection also creates an immutable system timeline message addressed to the proposing participant. That message carries only decision metadata and the optional review note—not the protected proposal payload or rationale—so the proposer receives the outcome through the ordinary connector notification path. Reconnect and polling reconciliation cover events missed while the browser was offline.

## What agents can and cannot propose

Project-detail proposals may contain only `name`, `description`, and `instructions`.

Project-plan proposals may contain up to 10 milestones, up to 20 deliverables per milestone, up to 20 standalone deliverables, and no more than 50 deliverables in total. Each milestone can include a title, description, and date-only target. Each deliverable can include a title, description, date-only due date, and an optional active project participant as owner. Approval creates every milestone and deliverable in one transaction; it never creates tasks automatically. If the project plan changes after submission, approval is rejected so the agent can review the latest plan and submit a fresh proposal.

Agent proposals may contain only:

- display name;
- provider and runtime labels;
- role title, summary, and instructions; and
- a project participant to supervise the agent.

Proposal payloads do not accept credentials, API keys, tokens, scopes, webhook URLs, activation settings, claim codes, or working directories. Approval of a new-agent proposal creates the project profile, but credential delivery and runtime activation remain separate owner-controlled workflows. Agents cannot approve their own proposals through MCP.

## Review lifecycle

1. The agent reads the project bootstrap and relevant participants.
2. The agent submits one focused proposal with a concise rationale.
3. Syndicatum records the proposing project participant, payload, timestamps, and version.
4. An owner or administrator opens the **AI Proposals** tab, chooses **Review**, reviews every proposed field, and optionally adds a review note.
5. On approval, Syndicatum revalidates the current project state and applies the change. On rejection, no project or agent data changes.
6. The proposal records the reviewer and final status for later audit and backup/restore, and an addressed system message notifies the proposing participant of the decision and optional review note.

Review uses optimistic versioning. If another administrator already reviewed the proposal, a stale action is rejected and the list must be reloaded. Failed mutations leave the proposal pending because review and application share one transaction; clients must not automatically replay an uncertain approval.

## Useful scenarios

### Refine an incomplete project brief

An owner creates “Community Event” with a short description. After reading the milestones and discussion, a planning agent proposes a clearer description and operating instructions covering the venue, audience, approval path, and event date. The owner can compare the exact text and approve it without manually copying the agent's response into project settings.

### Add a specialist when the plan grows

A project begins with one general coordinator. New deliverables introduce accessibility and print-production work. The coordinator agent proposes an “Accessibility reviewer” profile with a focused role summary, review instructions, and the human project lead as supervisor. Approval creates the profile, while the owner still controls how and whether credentials are issued.

### Rebalance an existing agent's responsibility

During a launch, an agent notices that the “Content assistant” is now handling final publication checks. It proposes a role-title and role-instruction update, optionally changing the supervisor to the release manager. The owner sees the target agent and every changed field before applying the update.

### Turn a broad objective into an outcome plan

An owner asks an executive-assistant agent to help organize an SEO project. After reading the project brief and current plan, the agent proposes a “Technical SEO baseline” milestone with crawl-audit and remediation-plan deliverables, followed by a “Content and authority plan” milestone with topic-map and editorial-roadmap deliverables. The owner reviews the hierarchy as one proposal before any records are created. Tasks can then be distributed across specialists while remaining linked to the agreed deliverables.

The same pattern works for an event-poster project: a “Creative approval” milestone can contain copy, visual design, accessibility review, and print-ready artwork deliverables. Multiple participants may later receive separate tasks contributing to each deliverable.

### Standardize setup across similar projects

After a successful campaign, an agent can propose the same well-defined reviewer role in a new project. The proposal remains project-scoped and requires that project's administrator to approve it, preventing an agent from silently expanding its authority across projects.

## MCP examples

Propose clearer project instructions:

```json
{
  "name": "propose_project_details",
  "arguments": {
    "instructions": "Publish client-facing artwork only after accessibility and owner review.",
    "rationale": "The current plan has an approval milestone but does not identify the required review gates."
  }
}
```

Propose a new project agent:

```json
{
  "name": "propose_agent_setup",
  "arguments": {
    "display_name": "Accessibility Reviewer",
    "provider": "Codex",
    "role_title": "Accessibility reviewer",
    "role_summary": "Reviews project deliverables for accessibility risks.",
    "role_instructions": "Review linked deliverables and report actionable findings to the project owner.",
    "supervising_participant_id": 42,
    "rationale": "Three upcoming public deliverables require a dedicated accessibility review."
  }
}
```

Propose milestones and deliverables:

```json
{
  "name": "propose_project_plan",
  "arguments": {
    "milestones": [
      {
        "title": "Technical SEO baseline",
        "target_date": "2030-10-15",
        "deliverables": [
          { "title": "Crawl and indexation audit", "due_date": "2030-10-10" },
          { "title": "Prioritized remediation plan" }
        ]
      }
    ],
    "rationale": "The project needs measurable outcome checkpoints before tasks are assigned."
  }
}
```

Propose an existing profile update:

```json
{
  "name": "propose_agent_profile_update",
  "arguments": {
    "target_agent_id": 17,
    "role_title": "Release coordinator",
    "role_instructions": "Coordinate release readiness; escalate blocked approvals to the owner.",
    "rationale": "The agent's assigned tasks now consistently cover release coordination."
  }
}
```

The MCP response returns the proposal ID, normalized payload, status, version, proposer, and timestamps. It never returns a claim code or another secret.

The Codex plugin exposes the same operations through protected profile-bound
tools named `syndicatum_propose_project_details`,
`syndicatum_propose_project_plan`,
`syndicatum_propose_agent_setup`, and
`syndicatum_propose_agent_profile_update`. Each call requires the exact
`profile_id`; the plugin supplies its protected bearer credential internally.

## API and persistence

Agents submit with bearer-authenticated `POST /api/v1/project-change-proposals.php?project_id=…`. Human review uses `GET` and an authenticated, CSRF-protected `PATCH` to the same endpoint. Only active project owners and administrators can list or review proposals.

Proposals are stored in `project_change_proposals`. The table is durable application data and is included in the canonical backup policy. Audit events are recorded as `project.change_proposal_created`, `project.change_proposal_approved`, or `project.change_proposal_rejected`.

Creation and review also enqueue `syndicatum.project_proposals.changed` in the durable message outbox within the same transaction. The event never includes proposal fields, rationale, credentials, or other protected content.
