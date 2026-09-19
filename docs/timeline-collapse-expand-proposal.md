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
  Hide the full body and message actions until expanded. Retain a compact,
  one-line reply-parent preview/link so the relationship remains visible. Keep
  the timeline marker and day grouping visible.
- Provide **Collapse results / Expand results** controls near the timeline
  tools. They apply to every loaded record matching the current filter, whether
  mounted onscreen or virtualized offscreen. They do not fetch older pages.
  Later pages and realtime arrivals matching that same view inherit its bulk
  mode; records outside the view do not change.
- The latest explicit bulk action clears individual overrides for the records
  it affects. A later individual toggle overrides bulk mode for that message.
  Refreshing the same view preserves these choices during the page session.
  Changing the filter/query starts a new view with expanded as its bulk default;
  previously assigned per-message choices remain on loaded records outside a
  search session. Switching projects resets both bulk mode and overrides. Do
  not persist this presentation state to the server.
- Search and filters keep their existing server-side semantics. Each new query
  generation initially expands its results once; refresh/polling within that
  generation must not reopen a manually collapsed match. Search-session
  overrides are separate from pre-search card choices: clearing the query
  restores the pre-search choices for previously loaded messages. A new query
  discards the prior search-session overrides. Direct jump to a loaded message
  always expands it, even after manual collapse, before mounting and focusing
  it. The existing “message not loaded” behavior remains unchanged.
- A reply preview link opens/locates its parent message as today; if the parent
  is loaded and collapsed, expand it before the jump. Collapsing a card never
  acknowledges it, marks it read, or changes its reply/addressee state.

## Implementation boundary

The app currently builds cards in `mountMessageCard` and supplies them to the
virtualized timeline in `renderTimeline` (`assets/app.mjs`). Keep presentation
state keyed by project and message ID, rather than changing canonical message
objects. The timeline component's virtualization, date groups, pagination, and
50-record request size remain intact. Before implementation, verify the Helper
timeline's mounting, height-cache invalidation, ResizeObserver, and scroll API
contract; do not assume app-only state updates are sufficient for offscreen
rows. Invalidate/re-measure affected offscreen rows after bulk changes. Avoid
rebuilding the entire timeline on every individual toggle.

For a toggle or bulk operation, capture the first visible message ID and its
top offset from the viewport before the height change. After measurement,
restore that card to the same offset, clamped to the scrollable bounds; if its
body disappeared, anchor to the card header, not a point inside the body. For
a direct jump, expansion and target mounting/measurement take precedence over
the old anchor: scroll the target into view and focus it only after it exists
in the DOM. A remount must not silently discard keyboard focus. Re-measure
after late font/image changes as well as after toggles.

Use a real `<button>` with `aria-expanded`, a stable `aria-controls` target,
and an accessible label such as “Collapse message 2338.” Hide the controlled
body/actions from both tab order and accessibility tree when collapsed. If the
currently focused link/action would be hidden, move focus to that card's
toggle first. Keep focus on the toolbar button for bulk actions. Close an open
card action menu before collapsing; do not collapse a card with an active
inline edit until that edit is saved or canceled. The visible icon and hit
target should follow existing workspace controls.

Derive the one-line preview from the already loaded body using text-only DOM
operations, never executable HTML. Normalize Markdown/whitespace for compact
display and recompute when a message is edited. A live deletion immediately
replaces the preview with the removed-message label and removes the hidden
original body. The preview must remain legible at mobile widths; it must not
add a server request or expose content to a user who could not already read
the full message.

## Acceptance checks

1. Individual and bulk controls work on desktop and mobile; keyboard and screen
   reader users can identify and operate them.
2. Collapsed cards retain identity, time, addressing/status, ID, and a useful
   preview; expansion restores the original card and actions without mutation.
3. Search results and loaded-message jumps reveal their target, including a
   collapsed reply parent. Filters, pagination, and the 50-record limit behave
   as before.
4. Expand one → collapse results → refresh → load next page obeys the latest
   bulk action; individual toggles made afterward remain stable. Clearing a
   filter/query and switching projects follow the lifecycle rules above.
5. New search results expand once per query generation, not on each poll;
   clearing search restores pre-search choices. Jumping to a manually collapsed
   loaded result or reply parent expands, mounts, measures, scrolls, and focuses
   it. A not-loaded target retains the existing notice.
6. Virtualized scrolling preserves the visible-card anchor through offscreen
   bulk changes, rapid toggles, remounts, and late font/image resize. Test
   loaded-but-unmounted cards explicitly and assert focus survives remount.
7. Hidden controls are absent from tab order/accessibility tree; collapsing a
   focused card moves focus to its toggle, bulk controls retain focus, and an
   active edit cannot be discarded by collapse.
8. Edited-while-collapsed previews refresh; deleted-while-collapsed cards
   immediately lose old content. No acknowledgement, read state, timeline
   ordering, or API payload changes occur solely because of a toggle.

## Not in this first iteration

Collapsing entire date groups, permanent per-user preferences, new search
ranking/highlighting, and server-side summaries can be evaluated after the
per-message interaction proves useful.
