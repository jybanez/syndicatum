# Human-reviewed AI project proposals

Use proposals when analysis reveals a durable project-setup improvement that a
human owner should review. A proposal is not a command: it is stored as
`pending` and never changes the project automatically.

## Choose the focused tool

- `syndicatum_propose_project_details` suggests a project name, description, or
  operating instructions.
- `syndicatum_propose_project_plan` suggests a bounded, create-only hierarchy of
  milestones and deliverables. Approval applies the hierarchy atomically.
- `syndicatum_propose_agent_setup` suggests a new project-scoped agent profile.
- `syndicatum_propose_agent_profile_update` suggests non-secret changes to an
  existing agent's profile, role, instructions, or supervisor.

Read bootstrap and relevant participants first. Submit one cohesive change with
a concise rationale grounded in observed project needs. Do not use proposals for
ephemeral status, ordinary task work, or changes that are already captured by a
task.

## Choose the correct record, not just the correct wording

When the user asks the agent to propose, draft for review, recommend for
approval, or formally suggest milestones or deliverables, call
`syndicatum_propose_project_plan`. The resulting durable proposal record is the
requested output. Do not paste the plan into `syndicatum_post_message`, create a
task containing the plan, or treat a conversational response as an equivalent
substitute. A timeline message may discuss requirements before submission or
briefly report the returned proposal ID and pending status afterward, but it
must not replace the proposal.

If `syndicatum_propose_project_plan` is not present in the current tool catalog,
say that the proposal cannot yet be submitted and ask for the Syndicatum plugin
to be updated or for a fresh chat after installation. Do not downgrade the
request into a normal message.

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
- An executive-assistant agent turns a broad SEO objective into reviewable
  milestones such as a technical baseline and content strategy, with concrete
  audit, remediation-plan, and editorial-roadmap deliverables beneath them.
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

Project plan:

```json
{
  "profile_id": "exact-profile-id",
  "milestones": [
    {
      "title": "Technical SEO baseline",
      "target_date": "2030-10-15",
      "deliverables": [
        { "title": "Crawl and indexation audit" },
        { "title": "Prioritized remediation plan" }
      ]
    }
  ],
  "rationale": "The project needs outcome checkpoints before task assignment."
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
