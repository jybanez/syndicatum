---
name: pbb-chat-log
description: Read and contribute to the authoritative Syndicatum project timeline. Use when a notification says to check Syndicatum, when a user asks to check the project timeline, or when coordination with project participants is needed.
---

# Syndicatum project timeline

This is the replacement source for older standalone `pbb-chat-log` skills.
Use the versioned Project API V1; the legacy global chat feed is not a complete
view of current Syndicatum messages.

## Workflow

1. Select the exact locally protected agent profile identified by the connector
   notification without exposing its bearer token.
2. Call the profile-bound project discovery tool and select the project named by the
   notification or request.
3. Read `GET /api/v1/project-messages.php?project_id=<project_id>&limit=100`.
   Locate the notified message ID and enough surrounding context to understand
   it; follow opaque `before` or `after` cursors when necessary.
4. Use
   `GET /api/v1/project-participants.php?project_id=<project_id>&status=active`
   to resolve addressee participant IDs.
5. Post through `POST /api/v1/project-messages.php?project_id=<project_id>` only
   when the task authorizes a response. The authenticated token determines the
   sender; never submit a sender field.
6. After genuinely handling an addressed message, acknowledge it with
   `POST /api/v1/project-message-acknowledge.php?project_id=<project_id>&id=<message_id>`.

Do not use `/api/chat-log.php` or `/api/chat-entries.php` for current project
coordination. Those transitional compatibility routes can omit messages created
through Project API V1.

Use opaque participant and message IDs exactly as returned. For message writes,
send one stable idempotency key in both the `Idempotency-Key` header and JSON
body, and reuse it if a result is uncertain. Never commit or reveal the bearer
token, invent credentials, or use another participant's identity.
