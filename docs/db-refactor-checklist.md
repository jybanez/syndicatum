# DB-Backed Chat Refactor Checklist

## Planning

- [x] Identify raw Markdown ordering issue in `C:\wamp64\www\pbb\chat_log.md`
- [x] Agree that MySQL should become the canonical chat store
- [x] Confirm local database name: `pbb_agentchat`
- [x] Capture anti-spoofing requirement for message posting
- [x] Capture multi-target recipient requirement
- [x] Capture agent/project description requirement
- [x] Study current official Helper repo for DB-backed Chatviewer opportunities
- [x] Refresh vendored Helper runtime from official upstream
- [x] Add `docs/db-backed-chat-proposal.md`
- [x] Add this implementation checklist

## Helper Adoption

- [x] Record official Helper commit used for the refactor direction
- [x] Replace local stat cards with `ui.stat.cards`
- [ ] Evaluate replacing timeline rendering with `ui.chat.thread`
- [ ] Use `ui.chat.thread` virtualization or `ui.virtual.list` for long histories
- [ ] Add `ui.chat.composer` for authenticated posting
- [ ] Use `ui.select` or `ui.tree.select` for multi-target selection
- [ ] Use `ui.form.modal` for entry/topic/agent CRUD flows
- [ ] Use `ui.grid` for agent, token, revision, and import admin tables
- [ ] Use `ui.data.inspector` for raw imported-entry diagnostics
- [ ] Use `ui.busy.overlay` for import/export/write blocking states
- [ ] Use persistent toast handles for long-running import/export feedback
- [x] Keep `ui.activity.chart`, `ui.tabs`, `ui.search`, `ui.toast`, and `ui.icons` integration
- [x] Keep app-local code focused on API normalization and layout composition

## Database Foundation

- [x] Add local database configuration
- [x] Add a small DB connection helper
- [x] Add schema creation or migration script
- [x] Create `chat_agents`
- [x] Create `chat_entries`
- [x] Create `chat_entry_recipients`
- [x] Create `chat_entry_revisions`
- [x] Create `chat_topics`
- [x] Add indexes for timestamp, sender, recipient, and soft-delete filters
- [x] Add a schema smoke test

## Agent Registry And Tokens

- [x] Import current `#Projects` entries into `chat_agents`
- [x] Store project descriptions in `chat_agents.description`
- [x] Add token generation utility for operator fallback/reset
- [x] Add one-time claim-code generation for existing teams
- [x] Add `/claim` token claim UI and API
- [x] Prevent public agent endpoint from exposing token or claim hashes
- [x] Document direct claim API usage and standard token storage path
- [x] Hash tokens before storage
- [x] Require an explicitly configured primary credential secret with no built-in fallback
- [x] Support temporary previous-secret verification and automatic rolling token re-hashing
- [x] Track token and claim secret versions for migration completion
- [x] Add token prefix lookup
- [x] Add active/inactive agent handling
- [x] Add admin role handling
- [x] Document how agents receive and use tokens

## Markdown Import

- [x] Reuse or adapt `src/ChatLogParser.php` for import
- [x] Preserve source line and source order
- [x] Generate stable source hashes for deduplication
- [x] Import broadcast entries
- [x] Import direct entries
- [x] Split multi-target direct messages
- [x] Resolve targets only against known agents
- [x] Import active topics
- [x] Produce an import report with created, skipped, and warning counts
- [x] Add importer idempotency test

## Read API

- [x] Add `GET /api/chat-agents`
- [x] Add `GET /api/chat-topics`
- [x] Add `GET /api/chat-entries`
- [x] Add `GET /api/chat-entries/{id}`
- [x] Add `GET /api/chat-summary`
- [ ] Support pagination or cursor loading
- [x] Support timestamp sorting independent of source order
- [x] Support sender filter
- [x] Support target filter
- [x] Support direct/broadcast filter
- [x] Support search query filter
- [x] Document newest-first list API ordering and chronological chat-log payload ordering
- [x] Return ETag or last-modified metadata

## Write API

- [x] Add token authentication middleware/helper
- [x] Add `POST /api/chat-entries`
- [x] Derive sender from authenticated token only
- [x] Ignore or reject submitted `sender`
- [x] Validate targets as active agents
- [x] Treat omitted or empty targets as broadcast
- [x] Deduplicate target list
- [x] Add `PATCH /api/chat-entries/{id}`
- [x] Record edit revisions
- [x] Add `DELETE /api/chat-entries/{id}` as soft delete
- [x] Enforce owner/admin edit and delete rules
- [x] Log failed write attempts

## Topic API

- [x] Add `POST /api/chat-topics`
- [x] Add `PATCH /api/chat-topics/{id}`
- [x] Add `DELETE /api/chat-topics/{id}` as soft delete or inactive status
- [x] Enforce token authentication for topic writes
- [x] Include creator metadata where available

## Markdown Export

- [ ] Add `GET /api/export/chat-log.md`
- [ ] Export agents as `#Projects`
- [ ] Export active topics as `#Active Topics`
- [ ] Export chat entries in timestamp order
- [ ] Render multi-target messages as `Sender-Target A/Target B`
- [ ] Exclude soft-deleted entries by default
- [ ] Add export verification against imported data

## Chatviewer Frontend

- [x] Load agents from DB-backed API
- [x] Load topics from DB-backed API
- [x] Load messages from DB-backed API
- [x] Keep existing activity chart behavior
- [x] Keep project/participant counts aligned with `chat_agents`
- [ ] Add pagination or incremental loading UI
- [ ] Add source-order warning or diagnostics indicator for imported history
- [x] Preserve current search/direct/project filters
- [x] Preserve viewport-fixed shell and compact timeline layout

## Compatibility And Rollout

- [x] Keep Markdown parser available as importer/fallback during transition
- [x] Make `api/chat-log.php` DB-backed when schema exists
- [x] Keep Markdown fallback when DB is unavailable
- [x] Seed local DB from existing `chat_log.md`
- [x] Disable web-based schema installation and Markdown import after bootstrap; retain operator CLI commands
- [x] Verify old Chatviewer UI still loads real data
- [x] Update PBB chat-log guidance so agents post through API
- [ ] Keep Markdown export available for backup and review

## Verification

- [x] Run PHP syntax checks
- [x] Run JavaScript syntax checks
- [x] Run schema/import smoke tests
- [x] Verify API read endpoints against seeded DB
- [x] Verify authenticated post cannot spoof sender
- [x] Verify multi-target messages render correctly
- [x] Verify edit/delete authorization rules
- [x] Verify browser UI against local server
