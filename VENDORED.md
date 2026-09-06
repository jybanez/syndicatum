# Vendored Assets

This project vendors minimal runtime subsets of the official PBB Helper and PBB
Realtime libraries so Syndicatum can use their supported browser components
without a cross-origin module dependency.

## Upstream Source

- Repository: `https://github.com/jybanez/helpers.pbb.ph.git`
- Pinned commit: `c6a460dfad400367fe39bd850e1255ae11c79999`
- Repository: `https://github.com/jybanez/realtime.pbb.ph.git`
- Pinned commit: `845c60bd27040f85ed0757c56f972c02b345bca9`

## Vendored Paths

- `vendor/pbb-helper/js/ui/ui.loader.js`
- `vendor/pbb-helper/dist/helpers.ui.bundle.min.js`
- `vendor/pbb-helper/dist/helpers.ui.bundle.min.css`
- `vendor/pbb-realtime/js/sdk/`

## Why These Files

This first implementation uses the helper library for:

- loader-based helper integration via `uiLoader`,
- shared bundled design tokens and component styles,
- shared search-field primitive,
- helper-managed tabs for the summary browser,
- helper-managed timeline rendering for the main message feed,
- helper-managed daily activity chart rendering,
- helper-managed empty states,
- helper-managed toast notifications,
- helper-managed shared icon rendering via `ui.icons`.

The current upstream helper line also includes newer primitives that should be preferred during the DB-backed refactor:

- measured-height `ui.timeline` virtualization for the main message stream,
- `ui.chat.composer` for authenticated message posting,
- `ui.stat.cards` for feed status metrics,
- `ui.busy.overlay` and persistent toast handles for import/export/write operations,
- `ui.grid`, `ui.data.inspector`, and `ui.form.modal` for admin/debug/edit surfaces,
- native `ui.timeline` virtualization for variable-height histories.

The rest of the viewer UI is app-specific composition built on top of those helper assets.

The Realtime SDK supplies the supported `RealtimeSocketClient`, envelope parser,
and room-join payload used by the Project timeline. Keeping the module graph on
the Syndicatum origin also avoids requiring Realtime to expose its SDK files
with cross-origin module headers.

## Refresh Procedure

1. Pull or clone the official upstream helper repository.
2. Verify the desired upstream commit.
3. Copy the required runtime files into this repo under `vendor/pbb-helper/`: `js/ui/ui.loader.js` and `dist/helpers.ui.bundle.min.*`.
4. Update this file if the pinned commit or copied paths change.

For the Realtime SDK, copy `public/js/sdk/` from the pinned official PBB
Realtime repository into `vendor/pbb-realtime/js/sdk/` and retain its directory
structure because the ES module imports are relative.

Do not refresh helper files from ad hoc local copies in other repos. Use the official upstream repository as the source of truth.
