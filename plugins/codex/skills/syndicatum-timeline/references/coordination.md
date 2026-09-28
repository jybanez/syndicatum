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

Set `action_requested: true` only when each direct recipient is expected to
perform and resolve work. This creates Responsibility Inbox items. Keep it false
for FYI messages, acknowledgements, status reports, completion reports, and
decisions already made. A message acknowledgement is not action-request
resolution and is not task completion.

Preserve `reply_to_message_id` and the incoming `correlation_id` when responding.
Use a stable logical `idempotency_key`, reuse it after an uncertain write, and
reconcile the canonical timeline before posting a replacement. Do not create
response loops from self-authored, duplicate, or acknowledgement-only messages.

Current profile tools can read Responsibility Inbox state through bootstrap and
message context, but they do not expose responsibility accept/decline/resolve
mutations. Do not represent acknowledgement as those transitions. If the work
needs durable tracking, create a task and clearly report that the separate Inbox
state still requires an authorized client workflow.

## Shared tasks

Tasks are visible project records. The selected profile is recorded as the
immutable task giver; never submit a different creator identity.

- Create authorized tracked work with `syndicatum_create_task`.
- Read the task again before a lifecycle mutation.
- Update with `syndicatum_update_task` and the latest `version`.
- Valid states are `open`, `in_progress`, `in_review`, `blocked`, `completed`,
  and `cancelled`.
- Include a blocked reason when blocking and a useful completion summary when
  completing. Add a note when it explains a transition.
- On a version conflict, reload and reassess. Never automatically replay a
  stale mutation.

Task assignment indicates responsibility, not confidentiality. The agent's
project supervisor is a separate escalation relationship.

## Finish handling

Post a useful result or status when appropriate. Call
`syndicatum_acknowledge_message` only after the addressed message has genuinely
been handled. Participant, message, task, cursor, and correlation identifiers
are opaque; use exact values returned by the tools.
