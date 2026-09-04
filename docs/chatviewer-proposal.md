# Syndicatum (PBB Chatviewer) Proposal

> **Project naming:** Syndicatum is the official project and product name. `chatviewer` is the repository and implementation name.

## Goal

Build `chatviewer` as an offline-capable, single-page visual reader for the shared PBB coordination log at `C:\wamp64\www\pbb\chat_log.md`.

The viewer should preserve the append-only nature of the raw log while making it significantly easier for Jojo to:

- scan discussions chronologically,
- distinguish broadcast vs direct cross-project messages,
- review active topics and project ownership,
- search and filter the thread without reading raw markdown,
- keep the viewer open while the log updates in the background.

## Source Context

The shared log currently has a stable structure:

- `#Projects`
- `#Active Topics`
- `#Chat log`

Within `#Chat log`, each message follows the rules documented in the log itself:

- broadcast format: `[yyyy-mm-dd hh:mm:ss]Name:Message`
- directed format: `[yyyy-mm-dd hh:mm:ss]Name-Target:Message`

This makes the log suitable for a parser-backed rendered view instead of a plain markdown reader.

## Proposed Architecture

Implement the project as a lightweight PHP + vanilla JavaScript application served directly from this repo root.

### Backend

Create a small PHP parser that:

- reads `C:\wamp64\www\pbb\chat_log.md`,
- extracts structured sections for `Projects`, `Active Topics`, and `Chat log`,
- parses each chat entry into:
  - `timestamp`
  - `sender`
  - `target` (optional)
  - `body`
  - derived flags such as `is_direct`
- preserves append-only chronological order from the file,
- emits metadata such as:
  - file last-modified time,
  - message count,
  - participant count,
  - direct-message count.

Expose the parsed result through a same-origin JSON endpoint such as `/api/chat-log.php`.

### Frontend

Build a single-page shell fixed to the viewport height.

Desktop layout:

- left column: log summary, active topics, filters, participant list
- center column: rendered timeline
- right column: selected message detail, stats, review context

Each column should scroll independently. The page itself should not scroll.

Responsive layout:

- keep the viewport-fixed shell,
- collapse the three desktop columns into tabbed panels on narrower screens,
- preserve independent internal scrolling for the active panel.

### Refresh Behavior

Use lightweight background polling against the JSON endpoint every 10-20 seconds.

Polling should:

- use ETag or last-modified checks,
- avoid full page reloads,
- update timeline and summary content when the log changes,
- preserve the current filter state and, where practical, the selected message.

## UX/UI Direction

Use the official PBB helper library locally vendored into this repo so the app runs fully offline.

Per the helper refactor playbook, the app integration should be helper-first and loader-based:

- integrate helper components through `uiLoader` registry keys,
- maximize documented helper components before introducing app-local UI,
- keep app-local code focused on data normalization, API wiring, and shell composition,
- treat project CSS as a thin layer over helper tokens, components, and helper-owned component CSS.

For this app, the preferred first-pass helper surface is:

- `ui.search`
- `ui.tabs`
- `ui.timeline`
- `ui.empty.state`
- `ui.toast`

### Visual Reading Model

The chat log should render as a review console rather than a code-like text dump:

- day separators for chronology
- sender chips with stable visual identity
- direct-message badges for `Sender -> Target`
- project/topic side summaries that remain visible while reviewing the log
- selected-message inspector for quick context
- explicit empty states when filters return no matches

## Vendoring Strategy

Vendor helper assets directly into this repo from the official upstream source:

- repository: `https://github.com/jybanez/helpers.pbb.ph.git`
- pinned upstream commit: `14f263a6bc4973581efbe165fa7a667306421a4f`

Document vendoring in `VENDORED.md`:

- source repository
- pinned commit
- copied paths
- refresh procedure

This keeps the project offline-safe and aligned with the cross-project guidance already discussed in the PBB chat log.

## Initial Deliverables

1. Proposal document and implementation checklist in `docs/`
2. Vendored helper assets with source documentation
3. PHP parser for `chat_log.md`
4. JSON API endpoint for the rendered app
5. Single-page fixed-shell UI with independent scroll regions
6. Background polling refresh
7. Basic verification of syntax and runtime paths

## Non-Goals For First Pass

These can be added later if needed, but are not required for the first implementation:

- markdown-rich message body rendering
- write-back/chat editing from the viewer
- websocket/live push updates
- user accounts or viewer-side preferences persistence
- multi-log support
