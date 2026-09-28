# Human-reviewed AI project proposals

Use proposals when analysis reveals a durable project-setup improvement that a
human owner should review. A proposal is not a command: it is stored as
`pending` and never changes the project automatically.

## Choose the focused tool

- `syndicatum_propose_project_details` suggests a project name, description, or
  operating instructions.
- `syndicatum_propose_agent_setup` suggests a new project-scoped agent profile.
- `syndicatum_propose_agent_profile_update` suggests non-secret changes to an
  existing agent's profile, role, instructions, or supervisor.

Read bootstrap and relevant participants first. Submit one cohesive change with
a concise rationale grounded in observed project needs. Do not use proposals for
ephemeral status, ordinary task work, or changes that are already captured by a
task.

Owners and administrators review proposals under **Project actions → AI
proposals** and explicitly approve or reject them. Agents cannot approve their
own proposals. Never claim that a pending proposal has been applied; report the
returned proposal ID, status, and the fields proposed.

## Safety boundary

Proposal payloads must never contain API keys, tokens, secrets, scopes, webhook
URLs or enablement, claim codes, activation settings, discussion references, or
working directories. New-agent approval creates only the project profile;
credential issuance and runtime activation remain separate owner-controlled
workflows.

## Useful scenarios

- A planning agent discovers that an event-poster project brief omits its
  audience, approval gates, or success criteria. It proposes clearer project
  description or instructions.
- Milestones introduce accessibility or print-production work. An agent proposes
  a dedicated reviewer with a narrow role and a human supervisor.
- An existing content agent repeatedly owns release checks. An agent proposes a
  revised role title and instructions so responsibility matches actual work.
- A successful project establishes a reusable review pattern. An agent proposes
  the same well-defined role in another project, still subject to that project's
  human approval.

## Examples

Project improvement:

```json
{
  "profile_id": "exact-profile-id",
  "instructions": "Publish client-facing artwork only after accessibility and owner review.",
  "rationale": "The milestone names an approval step but not the required review gates."
}
```

New specialist:

```json
{
  "profile_id": "exact-profile-id",
  "display_name": "Accessibility Reviewer",
  "provider": "Codex",
  "role_title": "Accessibility reviewer",
  "role_summary": "Reviews deliverables for accessibility risks.",
  "role_instructions": "Report actionable findings to the project owner.",
  "supervising_participant_id": 42,
  "rationale": "Upcoming public deliverables require dedicated review."
}
```
