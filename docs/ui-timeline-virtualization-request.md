# Helper Request: `ui.timeline` Virtualization

Status: fulfilled by Helper PR #68 and consumed by Syndicatum from Helper merge commit `c6a460d`.

## Context

Syndicatum uses `ui.timeline` to render a newest-first, day-grouped stream of variable-height PBB coordination messages. Its cursor API loads 200 recent messages initially and appends older pages when the reader reaches the bottom.

An evaluation of `ui.chat.thread` virtualization proved that measured-window rendering controls DOM growth, but the chat-bubble presentation and its bottom-anchor behavior do not fit Syndicatum's review timeline. `ui.virtual.list` is also not a safe fit because timeline rows have variable heights.

## Requested Additive Contract

Please evaluate measured-height virtualization directly in `ui.timeline`, preserving all existing non-virtual behavior and options.

Suggested options and callbacks:

- `enableVirtualization: false`
- `virtualThreshold`
- `virtualOverscan`
- `onRangeChange(range, state)` for observability
- `onReachEnd(meta)` or an equivalent threshold callback for cursor-based loading
- an end-threshold option in pixels

## Required Behavior

1. Preserve `groupByDate`, `onItemClick`, selected/app-owned classes, custom content slots, item metadata, and current layout/orientation behavior.
2. Support variable-height rows through measurement rather than a fixed row-height assumption.
3. Keep stable item identity by `id` across updates.
4. When older items are appended at the bottom, preserve the old/new page boundary instead of pinning to the new bottom or repeatedly firing the load callback.
5. When newer items are prepended at the top, keep a reader's current viewport anchored unless they were already near the top; readers at the top should see the new items.
6. Avoid duplicate `onReachEnd` calls while the consumer reports loading, and allow retry after loading completes or fails.
7. Continue to work inside a viewport-fixed parent with the timeline owning its scroll viewport.
8. Add regression coverage for variable heights, day grouping, append/prepend anchoring, selection/clicks, custom content lifecycle, and end-trigger de-duplication.

## Syndicatum Consumption Target

Syndicatum should be able to retain its current `ui.timeline` item mapping and styling, opt into virtualization, and replace its app-local bottom scroll listener with the Helper callback. The application will continue to own API cursors, loading/error state, and message ordering.
