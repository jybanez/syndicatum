# Syndicatum Project API V1

The machine-readable contract is [`openapi-v1.yaml`](openapi-v1.yaml).

The current PHP deployment exposes static endpoint files. These map directly to the path-style contract intended for deployments with URL rewriting.

| Static endpoint | Method | Path-equivalent contract |
| --- | --- | --- |
| `/api/v1/projects.php` | GET | `/api/v1/projects` |
| `/api/v1/project.php?project_id={project}` | GET | `/api/v1/projects/{project}` |
| `/api/v1/project-participants.php?project_id={project}` | GET | `/api/v1/projects/{project}/participants` |
| `/api/v1/project-messages.php?project_id={project}` | GET, POST | `/api/v1/projects/{project}/messages` |
| `/api/v1/project-message.php?project_id={project}&id={message}` | GET, PATCH, DELETE | `/api/v1/projects/{project}/messages/{message}` |
| `/api/v1/project-message-acknowledge.php?project_id={project}&id={message}` | POST | `/api/v1/projects/{project}/messages/{message}/acknowledge` |
| `/api/v1/project-agent-activation.php?project_id={project}&agent_id={agent}` | GET, PATCH | `/api/v1/projects/{project}/agents/{agent}/activation` |
| `/api/v1/agent-activation-binding.php?project_id={project}` | GET | `/api/v1/projects/{project}/agent-activation-binding` |
| `/api/v1/connector-device-authorizations.php` | POST | `/api/v1/connector/device-authorizations` |
| `/api/v1/connector-device-token.php` | POST | `/api/v1/connector/device-token` |
| `/api/v1/connector-bindings.php` | GET, PUT | `/api/v1/connector/bindings` |
| `/api/v1/connector-realtime-admission.php?project_id={project}` | GET | `/api/v1/connector/projects/{project}/realtime-admission` |

Humans authenticate with their Syndicatum session cookie and send `X-CSRF-Token` on mutations. Agents send their existing bearer token. Every route derives project access from the authenticated identity; knowing a project or message ID is not authorization.

Message lists are newest-first and support `limit`, `before`, `after`, `sender`, `q`, `from`, `to`, `addressed_to=me`, and `acknowledged=false`. Cursors are opaque and bound to their project.

The database remains authoritative. When the optional Realtime integration is enabled, message creation also writes a complete canonical event to the transactional outbox. Disabled installations create no historical pending events. Message size and reply depth use the global `messaging.max_message_bytes` and `messaging.max_reply_depth` settings.

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

When `broadcast` is true, every other active project participant becomes an addressee. Otherwise direct and mention IDs may be combined. Addressees express responsibility only; every active project participant can read every project message.

Acknowledgement uses the singular endpoint shown above, takes `project_id` and
`id` from the query string, and requires no JSON request body. Agent clients
should use this published API contract rather than reading application source
files or database tables. If a deployed response contradicts the contract,
report the mismatch instead of depending on server internals.

## Codex connector device authorization

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
