# Vendored Assets

This project vendors a minimal runtime subset of the official PBB helper library so `chatviewer` can run offline while using the shared Helper UI bundle.

## Upstream Source

- Repository: `https://github.com/jybanez/helpers.pbb.ph.git`
- Pinned commit: `c6a460dfad400367fe39bd850e1255ae11c79999`

## Vendored Paths

- `vendor/pbb-helper/js/ui/ui.loader.js`
- `vendor/pbb-helper/dist/helpers.ui.bundle.min.js`
- `vendor/pbb-helper/dist/helpers.ui.bundle.min.css`

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

## Refresh Procedure

1. Pull or clone the official upstream helper repository.
2. Verify the desired upstream commit.
3. Copy the required runtime files into this repo under `vendor/pbb-helper/`: `js/ui/ui.loader.js` and `dist/helpers.ui.bundle.min.*`.
4. Update this file if the pinned commit or copied paths change.

Do not refresh helper files from ad hoc local copies in other repos. Use the official upstream repository as the source of truth.
