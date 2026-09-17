# Timeline message collapse/expand — proposal

**Status:** Proposed interaction; not implemented.
**Scope:** Project timeline in desktop and mobile layouts. This is a presentation
change, not a change to message storage, search, permissions, or Project API V1.

## Problem and goal

Long messages make the timeline difficult to scan. A user looking for a buried
message must scroll through full bodies even when the sender, date, addressee,
or message ID is enough to identify it. Let users temporarily reduce message
cards to compact summaries and reopen individual cards without losing their
place or changing which records are loaded.

## Proposed behavior

- Keep **expanded** as the default for ordinary browsing, preserving today's
  reading experience. Each card gets a clearly labeled Collapse/Expand control
  in its header, usable by mouse, touch, and keyboard. Do not make the whole
  card clickable; its avatar, links, reply preview, and actions already have
  distinct behavior.
- A collapsed card retains sender, timestamp, addressee or broadcast indicators,
  acknowledgement/status styling, message ID, and a plain-text one-line body
  preview. Show a removed-message label instead of previewing deleted content.
  Hide the full body and message actions until expanded. Keep the timeline
  marker and day grouping visible.
- Provide **Collapse loaded / Expand loaded** controls near the timeline tools.
  These operate on the currently loaded, filtered message set only; they must
  not fetch older pages merely to collapse them. Newly fetched messages follow
  the current bulk mode until individually overridden.
- An individually toggled card overrides bulk mode for that message. Switching
  projects clears these transient overrides. Refreshing the same project
  preserves them during the current page session when message IDs are stable.
  Do not persist the potentially large per-message state to the server.
- Search and filters keep their existing server-side semantics. A matching card
  should be expanded when search results are first shown, and a direct jump to
  a loaded message should expand it before focusing or scrolling to it. If the
  user manually collapses a match afterward, respect that choice. The existing
  “message not loaded” behavior remains unchanged.
- A reply preview link opens/locates its parent message as today; if the parent
  is loaded and collapsed, expand it before the jump. Collapsing a card never
  acknowledges it, marks it read, or changes its reply/addressee state.

## Implementation boundary

The app currently builds cards in `mountMessageCard` and supplies them to the
virtualized timeline in `renderTimeline` (`assets/app.mjs`). Implement card
state there, keyed by project and message ID, rather than changing canonical
message objects. The timeline component's virtualization, date groups,
pagination, and 50-record request size remain intact. A collapse changes
rendered height, so verify scroll anchoring: toggling one card should not cause
unrelated cards to jump, and bulk collapse should leave a predictable viewport
position. Avoid rebuilding the entire timeline on every individual toggle.

Use a real `<button>` with `aria-expanded`, an accessible label such as
“Collapse message 2338,” and a controlled content region. Its visible icon
and hit target should follow existing workspace controls. The one-line preview
must remain legible at mobile widths and should be derived from the already
loaded body; it must not add a new server request or expose content to a user
who could not already read the full message.

## Acceptance checks

1. Individual and bulk controls work on desktop and mobile; keyboard and screen
   reader users can identify and operate them.
2. Collapsed cards retain identity, time, addressing/status, ID, and a useful
   preview; expansion restores the original card and actions without mutation.
3. Search results and loaded-message jumps reveal their target, including a
   collapsed reply parent. Filters, pagination, and the 50-record limit behave
   as before.
4. Newly loaded pages follow bulk mode, individual overrides remain stable
   through refresh/update, and switching projects resets transient state.
5. Virtualized scrolling remains stable for long messages, rapid toggling,
   bulk toggling, and mixed collapsed/expanded cards.
6. No acknowledgement, read state, timeline ordering, or API payload changes
   occur solely because a card is collapsed or expanded.

## Not in this first iteration

Collapsing entire date groups, permanent per-user preferences, new search
ranking/highlighting, and server-side summaries can be evaluated after the
per-message interaction proves useful.
