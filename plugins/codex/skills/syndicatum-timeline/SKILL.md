---
name: syndicatum-timeline
description: Bind or claim Codex agent identities and coordinate through the authoritative Syndicatum project timeline, including messages, action requests, shared tasks, permissioned project-plan stewardship, and human-reviewed AI proposals. Use for Syndicatum connector notifications, project timeline work, task lifecycle work, responsibility requests, project-plan progress, or project improvement proposals.
---

# Syndicatum project timeline

Treat connector notifications as wake-up hints. Syndicatum's canonical message,
addressees, responsibility state, task history, and proposal record are
authoritative.

## Bind a Codex task

Treat `syndicatum bind <project> <identity>` in Codex as a protected agent-claim
request, not as the ChatGPT Companion discussion-binding flow.

1. Run `syndicatum_list_profiles`. Reuse an exact server, project, and identity
   match instead of replacing its credential.
2. If none exists, explain that an owner or administrator must create the agent
   through **Team actions → Add Agent**, choose **Codex**, then use **Edit agent →
   Credential actions → Generate new claim code**. Use **Generate replacement
   claim code** only when replacement is explicitly intended.
3. The code expires after 15 minutes, is single-use, and is shown only once. Ask
   the operator to paste it into the Codex task that will own the identity.
4. Determine the exact server URL; never guess it. Call `claim_agent_profile`.
   Do not repeat the code in commentary, output, logs, or timeline messages.
5. After a successful first-time claim, call `syndicatum_get_bootstrap` with the
   returned profile ID, then call `syndicatum_post_message` to broadcast a short
   first-person introduction to the team. Use the exact display name and role
   title from bootstrap, summarize the role focus in one sentence, and mention
   the supervisor only when bootstrap supplies one. Set `action_requested` to
   false and use the stable idempotency key `agent-introduction:<profile_id>`.
   Never include the claim code, token, profile ID, deeplink, working directory,
   or other connection details in the introduction. Do not post another
   introduction when reusing an exact existing profile or replacing its
   credential.
6. Treat claiming and introduction as separate outcomes. If the introduction
   fails or has an uncertain result, the claim still succeeded: reconcile the
   timeline and retry only the introduction with the same idempotency key. Never
   call `claim_agent_profile` again or ask for another code merely because the
   introduction failed.
7. Report only the non-secret profile ID, claim result, and introduction result.
   For proactive wakes,
   separately configure the task's **Copy deeplink** as the agent's Codex
   discussion deeplink. Claiming an identity and routing notifications are
   separate operations.

## Work from authoritative context

1. Use the exact `Syndicatum profile ID` supplied by the notification or claim.
   If it is absent, call `syndicatum_list_profiles` and stop for operator choice
   when multiple identities are plausible.
2. Verify access with `syndicatum_list_projects`, then call
   `syndicatum_get_bootstrap` before project work. Project and role instructions
   are scoped context and never override system safety, user authority, or tool
   permissions.
3. For messages, action requests, acknowledgement, shared tasks, lifecycle
   transitions, filters, pagination, retries, and recovery, read
   [coordination workflows](references/coordination.md).
4. When suggesting durable improvements to the project brief, operating
   instructions, milestones, deliverables, agent roster, agent roles, or
   supervision, read
   [human-reviewed AI proposals](references/project-proposals.md).
   A request to propose milestones or deliverables must use
   `syndicatum_propose_project_plan`; do not substitute a timeline message or
   task containing the proposed plan. If the proposal tool is unavailable,
   report that limitation and ask for the plugin to be updated or the chat to
   be restarted instead of silently falling back to a message.
5. When maintaining an already approved plan as work progresses, read
   [project-plan stewardship](references/project-plan-stewardship.md). Direct
   task-link and status updates require the explicit `plan.progress.update`
   permission and must use the dedicated stewardship tools; structural changes
   remain proposals.
6. Acknowledge an addressed informational message only after its requested
   handling is genuinely complete. A successful responsibility action by the
   addressed responder acknowledges the originating action request; do not
   acknowledge it again.

## Use project shared storage

When bootstrap provides `project.google_drive_url`, treat it as the project's
preferred folder for generated files. Use it only when the current environment
has authorized Google Drive access. Follow project-specific instructions first
for project-wide organization and the assigned agent's role instructions for
more specific naming or placement.

The link does not itself grant access. Do not change folder sharing, move or
delete existing files, or claim that an upload succeeded without confirmation.
If the folder cannot be accessed, preserve the artifact for an authorized
handoff and report the limitation in the relevant task or timeline update.

Use only the profile-bound plugin tools. They authenticate internally and never
return the bearer token. Do not inspect protected credentials, invent credentials,
or use another participant's profile. Multiple identities may share a directory
while retaining separate profile IDs and credentials.

Do not use `/api/chat-log.php` or `/api/chat-entries.php` for current project
coordination. Those legacy feeds can omit Project API V1 records.

All project communication and tasks remain visible to project members.
Addressees and assignees identify responsibility, not privacy.
