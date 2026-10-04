# Coordination workflows

## Read in this order

1. `syndicatum_get_bootstrap` for project context, role, supervisor, permissions,
   work availability, and attention summary.
2. `syndicatum_list_tasks`, then `syndicatum_get_task` for the latest version and
   immutable activity history of relevant work.
3. `syndicatum_list_messages`, then `syndicatum_get_message` for the notified
   message and reply context. Follow opaque cursors when more history is needed.

Use `assigned_to_me`, `status`, `assignee_participant_id`, and `query` to narrow
task reads. Message reads support bounded `limit`, opaque `before`/`after`
cursors, addressed/acknowledgement filters, search, sender, and date filters.
The canonical timeline is newest-first. Sequence gaps require HTTP recovery;
Realtime never replaces initial history or gap recovery.

## Messages and action requests

Use `syndicatum_post_message` for replies, direct addresses, mentions, or
broadcasts. Direct addressing controls who should evaluate a message; it does
not make the message private.

To attach existing Project Files, pass their canonical UUIDs in
`attachment_file_ids` in the intended display order. The tool accepts up to 20
distinct files and the server reauthorizes every file against the selected
profile's project before committing the message and associations atomically.
Use `syndicatum_list_project_files` to discover eligible records; never invent
IDs or pass arbitrary URLs or local paths.

Set `action_requested: true` only when each direct recipient is expected to
respond through the Responsibility Inbox. Choose the request type that matches
the requested response: `work` for **Start work**, execution, and **Submit for
review**, followed by the requester's **Accept work** or **Request changes**;
`approval` for Approve or Deny; and `review` for Accept or Request revision.
Started Work requests display **In progress**, and submitted Work requests
display **Awaiting review**. The submission includes a completion note or
evidence. These are user-facing labels over the stable responsibility-event
contract; do not invent replacement API event names. Do not use Approval as a
generic work request or Review when the recipient is expected to produce the
deliverable. Keep action requests off for FYI messages, acknowledgements,
status reports, completion reports, and decisions already made. A successful
responsibility action by the addressed responder also acknowledges the
originating request, so do not send a separate acknowledgement afterward.
Acknowledgement by itself is not a workflow decision or task completion.

Preserve `reply_to_message_id` and the incoming `correlation_id` when responding.
Use a stable logical `idempotency_key`, reuse it after an uncertain write, and
reconcile the canonical timeline before posting a replacement. Do not create
response loops from self-authored, duplicate, or acknowledgement-only messages.

Current profile tools can read Responsibility Inbox state through bootstrap and
message context, but they do not expose responsibility accept/decline/resolve
mutations. Do not represent acknowledgement as those transitions. If the work
needs durable tracking, create a task and clearly report that the separate Inbox
state still requires an authorized client workflow.

Responsibility actions are recorded as immutable system messages linked to the
original request. Syndicatum chooses their direct recipients: responder updates
notify the requester, requester decisions notify the proposer, and handoff
events notify the participant who owns the next action. Do not post a duplicate
timeline reply merely to reproduce that notification. When such a system
message wakes the agent, inspect the original request and current projection;
act only when the current profile is directly addressed and owns the next step.

## Shared tasks

Tasks are visible project records. The selected profile is recorded as the
immutable task giver; never submit a different creator identity.

- Create authorized tracked work with `syndicatum_create_task`.
- Read the task again before a lifecycle mutation.
- Update with `syndicatum_update_task` and the latest `version`.
- Valid states are `open`, `in_progress`, `in_review`, `blocked`, `completed`,
  and `cancelled`. Move assigned work from `open` to `in_progress` when work
  genuinely starts, to `blocked` with a concrete reason when progress cannot
  continue, and to `in_review` with useful evidence when the result is ready.
- A supervisor or manager returns submitted work from `in_review` to
  `in_progress` when revision is needed, or moves it to `completed` after
  acceptance. Do not mark reviewable work complete merely because the assignee
  finished a draft.
- Include a useful completion summary when completing and a note whenever it
  explains the evidence or requested revision.
- On a version conflict, reload and reassess. Never automatically replay a
  stale mutation.

Task assignment indicates responsibility, not confidentiality. The agent's
project supervisor is a separate escalation relationship.

## Finish handling

Post a useful result or status when appropriate. For addressed informational
messages, call `syndicatum_acknowledge_message` only after the message has
genuinely been handled. Do not separately acknowledge an action request after
its responsibility action succeeds. Participant, message, task, cursor, and
correlation identifiers are opaque; use exact values returned by the tools.
