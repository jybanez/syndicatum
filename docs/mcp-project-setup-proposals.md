# MCP project and agent setup proposals

Syndicatum agents can help improve project details and agent setup without receiving authority to change project governance directly. The MCP exposes three proposal tools:

- `propose_project_details` suggests a project name, description, or operating instructions.
- `propose_agent_setup` suggests a new project-scoped agent profile.
- `propose_agent_profile_update` suggests changes to an existing agent profile or supervisor assignment.

Every tool creates a durable, auditable proposal with `pending` status. A human project owner or administrator reviews it from **Project actions → AI proposals** and explicitly chooses **Approve and apply** or **Reject**. Approval and the underlying project change are committed in one database transaction.

## What agents can and cannot propose

Project-detail proposals may contain only `name`, `description`, and `instructions`.

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
4. An owner or administrator opens **AI proposals**, reviews every proposed field, and optionally adds a review note.
5. On approval, Syndicatum revalidates the current project state and applies the change. On rejection, no project or agent data changes.
6. The proposal records the reviewer and final status for later audit and backup/restore.

Review uses optimistic versioning. If another administrator already reviewed the proposal, a stale action is rejected and the list must be reloaded. Failed mutations leave the proposal pending because review and application share one transaction; clients must not automatically replay an uncertain approval.

## Useful scenarios

### Refine an incomplete project brief

An owner creates “Community Event” with a short description. After reading the milestones and discussion, a planning agent proposes a clearer description and operating instructions covering the venue, audience, approval path, and event date. The owner can compare the exact text and approve it without manually copying the agent's response into project settings.

### Add a specialist when the plan grows

A project begins with one general coordinator. New deliverables introduce accessibility and print-production work. The coordinator agent proposes an “Accessibility reviewer” profile with a focused role summary, review instructions, and the human project lead as supervisor. Approval creates the profile, while the owner still controls how and whether credentials are issued.

### Rebalance an existing agent's responsibility

During a launch, an agent notices that the “Content assistant” is now handling final publication checks. It proposes a role-title and role-instruction update, optionally changing the supervisor to the release manager. The owner sees the target agent and every changed field before applying the update.

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

## API and persistence

Human review uses `GET /api/v1/project-change-proposals.php?project_id=…` and an authenticated, CSRF-protected `PATCH` to the same endpoint. Only active project owners and administrators can list or review proposals.

Proposals are stored in `project_change_proposals`. The table is durable application data and is included in the canonical backup policy. Audit events are recorded as `project.change_proposal_created`, `project.change_proposal_approved`, or `project.change_proposal_rejected`.
