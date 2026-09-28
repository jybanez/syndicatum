# Syndicatum Project API V1

The machine-readable contract is [`openapi-v1.yaml`](openapi-v1.yaml).
The provider-neutral coordination semantics and unresolved V1 freeze decisions
are in [`v1-coordination-contract.md`](v1-coordination-contract.md).

The current PHP deployment exposes static endpoint files. These map directly to the path-style contract intended for deployments with URL rewriting.

| Static endpoint | Method | Path-equivalent contract |
| --- | --- | --- |
| `/api/v1/projects.php` | GET | `/api/v1/projects` |
| `/api/v1/project.php?project_id={project}` | GET | `/api/v1/projects/{project}` |
| `/api/v1/project-bootstrap.php?project_id={project}` | GET | `/api/v1/projects/{project}/bootstrap` |
| `/api/v1/project-participants.php?project_id={project}` | GET | `/api/v1/projects/{project}/participants` |
| `/api/v1/project-tasks.php?project_id={project}` | GET, POST | `/api/v1/projects/{project}/tasks` |
| `/api/v1/project-task.php?project_id={project}&id={task}` | GET, PATCH | `/api/v1/projects/{project}/tasks/{task}` |
| `/api/v1/project-change-proposals.php?project_id={project}` | POST | `/api/v1/projects/{project}/change-proposals` |
| `/api/v1/project-plan.php?project_id={project}` | GET | `/api/v1/projects/{project}/plan` |
| `/api/v1/project-milestones.php?project_id={project}` | POST | `/api/v1/projects/{project}/milestones` |
| `/api/v1/project-milestone.php?project_id={project}&id={milestone}` | PATCH | `/api/v1/projects/{project}/milestones/{milestone}` |
| `/api/v1/project-deliverables.php?project_id={project}` | POST | `/api/v1/projects/{project}/deliverables` |
| `/api/v1/project-deliverable.php?project_id={project}&id={deliverable}` | PATCH | `/api/v1/projects/{project}/deliverables/{deliverable}` |
| `/api/v1/project-status-summary.php?project_id={project}` | GET | `/api/v1/projects/{project}/status/summary` |
| `/api/v1/project-status-task-progress.php?project_id={project}` | GET | `/api/v1/projects/{project}/status/task-progress` |
| `/api/v1/project-status-plan.php?project_id={project}` | GET | `/api/v1/projects/{project}/status/plan` |
| `/api/v1/project-status-activity.php?project_id={project}&days={7\|14\|30}` | GET | `/api/v1/projects/{project}/status/activity` |
| `/api/v1/project-status-attention.php?project_id={project}&limit={1..20}&before={task}` | GET | `/api/v1/projects/{project}/status/attention` |
| `/api/v1/project-status-team.php?project_id={project}` | GET | `/api/v1/projects/{project}/status/team` |
| `/api/v1/project-status-integrations.php?project_id={project}` | GET | `/api/v1/projects/{project}/status/integrations` |
| `/api/v1/project-responsibility-inbox.php?project_id={project}` | GET | `/api/v1/projects/{project}/responsibility-inbox` |
| `/api/v1/project-messages.php?project_id={project}` | GET, POST | `/api/v1/projects/{project}/messages` |
| `/api/v1/project-message.php?project_id={project}&id={message}` | GET, PATCH, DELETE | `/api/v1/projects/{project}/messages/{message}` |
| `/api/v1/project-message-acknowledge.php?project_id={project}&id={message}` | POST | `/api/v1/projects/{project}/messages/{message}/acknowledge` |
| `/api/v1/project-integrations.php?project_id={project}` | GET, POST, PATCH, DELETE | `/api/v1/projects/{project}/integrations` |
| `/api/v1/project-integration-credentials.php?project_id={project}` | POST, DELETE | `/api/v1/projects/{project}/integration-credentials` |
| `/api/v1/integration-events/{integration_public_id}/{credential}` | POST | `/api/v1/integrations/{integration_public_id}/events/{credential}` |
| `/api/v1/project-agent-activation.php?project_id={project}&agent_id={agent}` | GET, PATCH | `/api/v1/projects/{project}/agents/{agent}/activation` |
| `/api/v1/agent-activation-binding.php?project_id={project}` | GET | `/api/v1/projects/{project}/agent-activation-binding` |
| `/api/v1/discussion-providers.php` | GET | `/api/v1/discussion-providers` |
| `/api/v1/connector-device-authorizations.php` | POST | `/api/v1/connector/device-authorizations` |
| `/api/v1/connector-device-token.php` | POST | `/api/v1/connector/device-token` |
| `/api/v1/connector-bindings.php` | GET | `/api/v1/connector/bindings` |
| `/api/v1/connector-realtime-admission.php?project_id={project}` | GET | `/api/v1/connector/projects/{project}/realtime-admission` |
| `/api/v1/connector-pending-notifications.php?provider={provider}` | GET | `/api/v1/connector/pending-notifications` |
| `/api/v1/connector-notification-deliveries.php` | POST | `/api/v1/connector/notification-deliveries` |
| `/api/v1/connector-agent-replies.php` | POST | `/api/v1/connector/agent-replies` |

Human-only application endpoints such as `/api/v1/notifications.php`,
`/api/v1/profile.php`, `/api/v1/registration-activation.php`, and
`/api/v1/project-templates.php` are documented in
[`application-surfaces.md`](application-surfaces.md). They are not part of the
provider-neutral agent contract unless their endpoint explicitly accepts an
agent bearer identity.

Humans authenticate with their Syndicatum session cookie and send `X-CSRF-Token` on mutations. Agents send their existing bearer token. Every route derives project access from the authenticated identity; knowing a project or message ID is not authorization.

The proposal endpoint accepts active project-agent bearer identities only. Its
`proposal_type` is `project_details`, `project_plan`, `agent_setup`, or `agent_profile_update`;
the allowed fields and human review lifecycle are documented in
[`mcp-project-setup-proposals.md`](mcp-project-setup-proposals.md). The same PHP
endpoint retains its human-only GET and PATCH review operations.

## Participant representation

`GET /api/v1/project-participants.php` returns normalized human and agent
participants. The shared fields support the Team directory and adaptive
Participant profile:

```json
{
  "id": 42,
  "project_id": 2,
  "kind": "agent",
  "identity_id": 39,
  "display_name": "Test ChatGPT Agent",
  "avatar_url": null,
  "status": "active",
  "role": "agent",
  "joined_at": "2026-09-06 11:20:00",
  "last_message_at": "2026-09-15 14:08:00",
  "message_count": 24,
  "provider": "chatgpt",
  "runtime": null,
  "capabilities": []
}
```

`joined_at` is the participant's project-participation creation time.
`last_message_at` is the latest non-deleted project message sent by that
participant, or `null`. `message_count` counts that participant's non-deleted
messages in the project and may validly be zero. These values describe durable
project activity; they do not claim that a participant is currently online or
that an agent connector is healthy.

Human account metadata is permission-scoped. A human participant receives their
own `email` and `authentication_source`; project owners and project
administrators receive those fields for human participants they manage. Ordinary
members do not receive another human's account metadata, and agents receive none.
The client maps `authentication_source` to a human-readable sign-in method.

Agent-only `provider`, `runtime`, and `capabilities` fields are returned when
configured. Clients must omit unavailable optional rows instead of displaying
invented values such as “Unspecified.” Internal participant and underlying
user/agent identifiers remain necessary for authenticated API operations, but
the standard UI exposes them only in the project-administrator Technical details
disclosure.

Message lists are newest-first and support `limit`, `before`, `after`, `sender`, `message_kind`, `q`, `from`, `to`, `addressed_to=me`, and `acknowledged=false`. `sender` accepts one positive participant ID or up to 100 comma-separated positive participant IDs; a message matches when its sender is in that set. `message_kind` accepts `participant` or `system`. `acknowledged=true` is rejected with `422 VALIDATION_FAILED`; it does not provide an acknowledged-only filter. Project, participant, and message IDs are positive integers in JSON; query parameters use their decimal representation. Public project UUIDs and cursors remain strings. Cursors are opaque and bound to their project.

The default message page is 50 records; clients may explicitly request 1–200 for history or gap recovery. The server fetches one additional ID internally to determine whether another page exists, but returns no more than the requested limit.

The database remains authoritative. When the optional Realtime integration is enabled, message creation also writes a complete canonical event to the transactional outbox. A connected browser uses the same-origin vendored PBB Realtime JavaScript SDK, consumes that complete event without fetching the message again, and does not periodically poll for newer messages. After reconnecting it performs one HTTP gap-recovery request. Periodic newer-message polling is reserved for Realtime-disabled projects; initial history, pagination, filters, and manual refresh remain HTTP operations. Disabled installations create no historical pending events. Message size and reply depth use the global `messaging.max_message_bytes` and `messaging.max_reply_depth` settings.

Message creation accepts:

```json
{
  "body": "Please review this decision.",
  "direct_participant_ids": [12],
  "mention_participant_ids": [15],
  "broadcast": false,
  "reply_to_message_id": null,
  "idempotency_key": "provider-run-42-message-1",
  "correlation_id": "provider-run-42"
}
```

When `broadcast` is true, every other active project participant becomes an addressee. Otherwise direct and mention IDs may be combined. A direct address identifies an expected responder; a mention calls attention without itself requiring a reply. Both reasons create addressee records eligible for acknowledgement, which is not task completion. Every active project participant can read every project message.

For newly created keyed messages, an identical logical request replays the original message with HTTP 200 and `idempotent_replay: true`. Reusing the same project/sender key for a different body, reply parent, correlation ID, or effective addressing returns HTTP 409 `IDEMPOTENCY_KEY_CONFLICT`. Address lists are normalized for ordering, duplicates, and direct-over-mention precedence before comparison. Messages created before the request-fingerprint migration retain their historical replay behavior because their original request cannot be reconstructed reliably after edits. Never reuse a key for a different logical message; reconcile uncertain responses using the sender-scoped key lookup.

Acknowledgement uses the singular endpoint shown above, takes `project_id` and
`id` from the query string, and requires no JSON request body. Agent clients
should use this published API contract rather than reading application source
files or database tables. If a deployed response contradicts the contract,
report the mismatch instead of depending on server internals.

## Connector device authorization

The connector begins with an unauthenticated device-authorization request. The
response contains a one-time device code, a human verification URL/code, and a
short-lived PBB Realtime admission restricted to one random authorization room.
The connector joins that room and performs one token exchange immediately after
joining, which covers approval that happened during connection setup. If still
pending, it waits for `connector.authorization.approved` and then
performs the one-time HTTPS exchange.

Authorization admissions and approval events use a dedicated Realtime project
scope configured as `realtime.connector_authorization_project_code`. Each
admission can join only `syndicatum.connector.authorization.{authorization_id}`;
the publisher policy permits only that room prefix and the approval event type.

The approval event contains only the random authorization ID and approval
status. It never contains the device credential, Codex conversation ID, working
directory, browser session, or project-agent token. After exchange, the device
bearer credential can discover bindings belonging to its approving human and
obtain exact-room project Realtime admission. Device credentials are stored as
hashes server-side and can be revoked independently of project-agent tokens.

Project owners and administrators link an agent to an existing provider
discussion through the agent management surface. The client loads provider field
metadata from `discussion-providers.php`; for Codex, the user pastes a
`codex://threads/{thread_id}` deeplink. Syndicatum validates that reference,
stores its normalized discussion ID with the provider code, and reconstructs the
canonical reference for later editing. The working-directory hint is optional
and may be ignored on a device where that path does not exist. A discussion
binding is shared across the user's authorized devices; device records are for
authorization, discovery, and revocation rather than owning separate routes.

Connector bindings are filtered by an explicit provider. Codex requests
`provider=codex`; the browser companion requests `provider=chatgpt` and `provider=gemini` and receives
only enabled `browser_companion` bindings. ChatGPT recovery remains metadata-only.
Gemini recovery also returns the addressed message body to the authorized device
because its two-way bridge must place authoritative content in the exact bound
discussion. After confirming a provider user turn, the companion records delivery
idempotently without acknowledging the message. For Gemini, it then captures the
matching settled assistant turn and submits it to the binding-scoped reply endpoint.
The server verifies device ownership, project membership, active binding, target
agent, and original addressee; posts one idempotent reply as that agent; and only
then acknowledges the original message. No project-agent credential is sent to
the browser.
