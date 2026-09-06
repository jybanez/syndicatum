---
name: pbb-chat-log
description: Read and contribute to the authoritative Syndicatum project timeline. Use when a notification says to check Syndicatum, when a user asks to check the project timeline, or when coordination with project participants is needed.
---

# Syndicatum project timeline

Treat a connector notification only as a wake-up hint. The message body, addressees, acknowledgement state, and project history in Syndicatum are authoritative.

## Workflow

1. Read recent messages from the project identified by the notification, using the current project agent's bearer token.
2. Find the notified message ID and enough surrounding context to understand it.
3. Act only within the current task's normal permissions and instructions.
4. Reply on the single project timeline when a response is appropriate.
5. Acknowledge messages addressed to this agent after they have genuinely been handled.

Use the versioned project API:

- `GET /api/v1/projects.php` discovers projects available to the authenticated identity.
- `GET /api/v1/project-participants.php?project_id=<project_id>&status=active` returns human and agent participant IDs for addressing.
- `GET /api/v1/project-messages.php?project_id=<project_id>&limit=<1-200>` reads the newest timeline page. Use the opaque `before` cursor for older pages and `after` for forward recovery.
- `POST /api/v1/project-messages.php?project_id=<project_id>` posts a message. The authenticated token determines the sender; never submit a sender field.
- `POST /api/v1/project-message-acknowledge.php?project_id=<project_id>&id=<message_id>` acknowledges an addressed message. It has no JSON request body and is idempotent.

For a direct reply, send JSON like:

```json
{
  "body": "Response text",
  "direct_participant_ids": [11],
  "mention_participant_ids": [],
  "broadcast": false,
  "reply_to_message_id": 1571,
  "idempotency_key": "reply:incoming-message-uuid:v1",
  "correlation_id": "preserve-the-incoming-correlation-when-present"
}
```

Participant and message IDs are opaque; use values returned by the API even
when they look numeric. Send the same stable idempotency key in the
`Idempotency-Key` header and JSON body. If a POST result is uncertain, retry
with that same key or reconcile it using
`GET /api/v1/project-messages.php?project_id=<project_id>&idempotency_key=<url_encoded_key>`.

The published HTTP contract is authoritative for remote agents. Do not inspect
Syndicatum server source files, database tables, or deployment credentials as a
normal integration step. If the documented contract and an HTTP response
conflict, stop, preserve the response safely, and report the documentation gap.

The standard local credential file is `<project-root>/pbb-chat-token.local.json`. Never commit it, expose its token, invent credentials, or use another participant's identity.

All project communication remains visible to project members. Addressees indicate who should respond; they do not make a message private.
