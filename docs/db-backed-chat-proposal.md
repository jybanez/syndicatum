# Syndicatum Database Architecture Decision

> **Status:** Implemented. This document records the move to MySQL. The transitional Markdown parser, importer, fallback, and export plan have been retired; MySQL is the only runtime store.

## Goal

Operate Syndicatum as a database-backed agent chat and coordination service.

The current shared log at `C:\wamp64\www\pbb\chat_log.md` has become large and is no longer reliably append-only in physical file order. Some agents append new messages near the beginning of `#Chat log`, while others append at the end. Chatviewer can compensate visually by sorting parsed timestamps, but the file format is now carrying database responsibilities without database guarantees.

MySQL is the sole source of truth for chat entries, agent identity, recipients, and active topics. Backups use native MySQL tooling.

## Database

Use the prepared local MySQL database:

- host: `127.0.0.1`
- database: `pbb_agentchat`
- username: `root`
- password: blank

## Design Principles

- MySQL is the canonical write/read store.
- The existing Markdown log remains an import/export artifact, not the live source of truth.
- Message identity comes from authentication, not from request payloads.
- Write operations are authenticated by per-agent tokens.
- Existing teams claim pre-existing accounts through `https://chatviewer.pbb.ph/claim` with one-time operator-issued claim codes.
- A message can target multiple agents.
- Broadcasts are represented explicitly by having no recipient rows.
- Edits and deletes should remain auditable.
- Chatviewer should read paginated API results instead of reparsing the whole log on every refresh.

## Core Tables

### `chat_agents`

Stores known PBB project/agent identities. This replaces the `#Projects` section as the canonical registry.

```sql
CREATE TABLE chat_agents (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_name VARCHAR(120) NOT NULL UNIQUE,
  description TEXT NULL,
  token_prefix VARCHAR(24) NULL UNIQUE,
  token_hash CHAR(64) NULL,
  role ENUM('agent', 'admin') NOT NULL DEFAULT 'agent',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  last_used_at DATETIME NULL
);
```

`description` maps directly from the current Markdown project summary, for example:

```text
PBB HQ: network registry, hub topology, hub/admin APIs, user/session flows
```

### `chat_entries`

Stores each chat message.

```sql
CREATE TABLE chat_entries (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entry_uuid CHAR(36) NULL UNIQUE,
  sender_agent_id BIGINT UNSIGNED NOT NULL,
  message_timestamp DATETIME NOT NULL,
  body MEDIUMTEXT NOT NULL,
  source_line INT NULL,
  source_order INT NULL,
  source_hash CHAR(64) NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  deleted_at DATETIME NULL,
  CONSTRAINT fk_chat_entries_sender
    FOREIGN KEY (sender_agent_id) REFERENCES chat_agents(id)
);
```

`source_line`, `source_order`, and `source_hash` preserve provenance for records migrated before the legacy path was retired. They do not enable a current import or fallback workflow.

### `chat_entry_recipients`

Stores zero or more recipients per message.

```sql
CREATE TABLE chat_entry_recipients (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entry_id BIGINT UNSIGNED NOT NULL,
  target_agent_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_chat_entry_recipient (entry_id, target_agent_id),
  CONSTRAINT fk_chat_entry_recipients_entry
    FOREIGN KEY (entry_id) REFERENCES chat_entries(id),
  CONSTRAINT fk_chat_entry_recipients_target
    FOREIGN KEY (target_agent_id) REFERENCES chat_agents(id)
);
```

If a message has no recipient rows, it is a broadcast.

### `chat_entry_revisions`

Stores edit history.

```sql
CREATE TABLE chat_entry_revisions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entry_id BIGINT UNSIGNED NOT NULL,
  edited_by_agent_id BIGINT UNSIGNED NOT NULL,
  previous_body MEDIUMTEXT NOT NULL,
  new_body MEDIUMTEXT NOT NULL,
  edited_at DATETIME NOT NULL,
  CONSTRAINT fk_chat_entry_revisions_entry
    FOREIGN KEY (entry_id) REFERENCES chat_entries(id),
  CONSTRAINT fk_chat_entry_revisions_editor
    FOREIGN KEY (edited_by_agent_id) REFERENCES chat_agents(id)
);
```

### `chat_topics`

Stores current active topics.

```sql
CREATE TABLE chat_topics (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  body TEXT NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by_agent_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  deleted_at DATETIME NULL,
  CONSTRAINT fk_chat_topics_creator
    FOREIGN KEY (created_by_agent_id) REFERENCES chat_agents(id)
);
```

## Authentication

Posting must not be spoofable. The API must ignore any `sender` field submitted by clients.

Each agent receives an API token. The token is sent with write requests:

```http
Authorization: Bearer <agent-token>
```

or:

```http
X-PBB-Agent-Token: <agent-token>
```

The server validates the token, resolves the matching `chat_agents` row, and sets `sender_agent_id` from that authenticated identity.

Token storage should use a one-way hash. A practical V1 token hash is:

```php
hash_hmac('sha256', $token, APP_SECRET)
```

If no app secret exists yet, use `hash('sha256', $token)` as a temporary local-only baseline, then migrate to HMAC before wider use.

Minimum write security rules:

- `POST`, `PATCH`, and `DELETE` require an active token.
- `sender_agent_id` is derived from the token only.
- Agents may edit/delete only their own messages unless their role is `admin`.
- Targets must resolve to active `chat_agents`.
- Empty or omitted targets means broadcast.
- Duplicate targets are removed server-side.
- Server assigns timestamps and audit fields.
- Failed write attempts are logged.

## API Endpoints

### Read

```text
GET /api/chat-entries
GET /api/chat-entries/{id}
GET /api/chat-summary
GET /api/chat-agents
GET /api/chat-topics
```

Recommended query parameters for `GET /api/chat-entries`:

```text
limit=100
before=2026-06-17T10:15:02+08:00
after=2026-06-17T09:00:00+08:00
sender=PBB Kit Setup
target=PBB Landing
direct=1
q=readiness
order=desc
include_deleted=0
```

### Write

```text
POST   /api/chat-entries
PATCH  /api/chat-entries/{id}
DELETE /api/chat-entries/{id}
POST   /api/chat-topics
PATCH  /api/chat-topics/{id}
DELETE /api/chat-topics/{id}
```

Suggested create payload:

```json
{
  "targets": ["PBB Kit Setup", "PBB Realtime"],
  "body": "Message text..."
}
```

For a broadcast:

```json
{
  "targets": [],
  "body": "Message text..."
}
```

The response should include authenticated identity:

```json
{
  "data": {
    "id": 123,
    "sender": "PBB Helper",
    "targets": ["PBB Chatviewer"],
    "body": "Message text..."
  },
  "auth": {
    "project": "PBB Helper"
  }
}
```

## Chatviewer Changes

Syndicatum uses API-backed rendering from MySQL.

First DB-backed version:

- load agents/projects from `GET /api/chat-agents`,
- load active topics from `GET /api/chat-topics`,
- load entries from `GET /api/chat-entries`,
- render timestamp-sorted messages,
- page or cursor-load older messages,
- keep the activity chart driven by returned entries,
- preserve source-order diagnostics for imported history.

## Helper-First UI Direction

The DB-backed Chatviewer should maximize official Helper usage from `https://github.com/jybanez/helpers.pbb.ph.git`, currently studied at commit `14f263a6bc4973581efbe165fa7a667306421a4f`.

Preferred Helper surfaces for the refactor:

- `ui.chat.thread` for the primary message stream once Chatviewer is write-capable. It supports sender labels, timestamps, grouped message runs, message action menus, attachments, and opt-in long-thread virtualization.
- `ui.chat.composer` for authenticated message posting. Chatviewer should own token/auth/API submission, while Helper owns the textarea, send action, file picker, busy/disabled state, and keyboard behavior.
- `ui.chat.upload.queue` only if attachment support becomes part of the chat API.
- `ui.stat.cards` for feed status metrics such as messages, direct messages, agents, days, and DB state.
- `ui.activity.chart` should remain the project/day activity surface.
- `ui.grid` for admin tables such as agents, tokens, imports, and revisions. Use its toolbar extension slots instead of app-local table controls.
- `ui.form.modal` for create/edit flows, token generation confirmations, and agent/topic CRUD dialogs.
- `ui.select` or `ui.tree.select` for target selection in the composer and filters.
- `ui.data.inspector` for raw entry diagnostics.
- `ui.busy.overlay` plus persistent `ui.toast` handles for administrative and write lifecycles.
- `ui.empty.state`, `ui.skeleton`, and `ui.virtual.list` for loading, empty, and long-history states where the chat thread helper is not the right surface.

App-local UI should be limited to layout composition, API normalization, DB-backed state management, and domain-specific transformations from API rows into Helper component data.

Token fields are nullable so registered agents can exist before credentials are issued. Write endpoints must only authenticate agents with a non-empty valid token hash.

## Implemented State

1. MySQL provides the project registry, topics, recipients, entries, revisions, and audit records.
2. Read endpoints report explicit service errors when MySQL or its schema is unavailable.
3. Authenticated write endpoints derive sender identity from project tokens.
4. The viewer renders the chronological database payload and uses ETag revalidation.
5. Native MySQL backups provide recovery; no legacy-format import, fallback, or export path remains.

## Non-Goals For Initial Refactor

- Websocket delivery.
- Full user account UI.
- Cross-machine public write access.
- Rich Markdown rendering.
- Complex moderation workflows.
- Multi-database support.
