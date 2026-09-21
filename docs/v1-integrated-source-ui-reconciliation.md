# Offline integrated source/UI candidate

This is an isolated candidate, not a deployment or approval to merge. It combines the preserved 2026-09-21 live source snapshot with the protected-main V1 additions. The live WAMP checkout and database were not edited.

## KEEP / ADD / CHANGE

- **KEEP:** The live three-pane workspace, project timeline, collapse/navigation behavior, compact message composer, and Helper `.185` revision. The Helper bundle, loader, optional icon modules, documentation, and license files come from the preserved live snapshot.
- **ADD:** Protected-main Admin Backup / Restore and delivery-health routes, responsibility inbox/evidence UI, and username-capable sign-in/registration. These use the protected-main backend contracts and the live shell.
- **CHANGE:** The live Administrator menu now reaches Backup / Restore and delivery health; the live project header switches between Timeline and Responsibility Inbox. Composer action validation stays non-busy until the message and addressee checks pass. Three surface assertions now match retained live UI markup and the retained Helper revision.

## Boundaries and conflicts

The source/UI integration commit did not change the database schema/upgrader, BaselineInstaller, backup backend, Companion, live WAMP state, security-remediation configuration, or deployment/release policy. The protected-main MCP security context remains unchanged. A stale static surface assertion for the older `$bindingContext !== null` expression was subsequently corrected to the existing `$contextAuthorized` behavior in the accepted test-only follow-up.

The disposable browser clone used the preserved live SQL dump, the separately accepted authenticated legacy uplift, and a PHP 8.3 preview server. The test password and private paths were confined to the disposable clone. The synthetic preview master key could not decrypt the preserved encrypted Realtime signing setting, so browser console 503s for Realtime admission were preview-specific, not a verified production defect. A controlled broadcast post succeeded only in the disposable clone.

## Offline checks and evidence

- `node --check assets/app.mjs`: pass.
- `node tests/responsibility-client.mjs`: 5/5 pass.
- `tests/responsibility-reducer.php`: 10/10 pass.
- `tests/responsibility-events.php`: pass, including concurrent-writer and inbox rebuild cases.
- `tests/surfaces.php`: 33/33 in the source-only series after the accepted MCP test correction and removal of one Apache-hardening-only test whose rules remain on the separate security track.
- `git diff --check`: pass.
- Browser: desktop timeline and Admin Backup / Restore; mobile timeline, responsibility inbox, Backup / Restore, and backup modal at 390×844 without horizontal overflow. Empty message produced the Helper validation alert without entering busy state; a valid broadcast appeared in the disposable timeline.

Screenshots remain in the preserved isolated review worktree under `output/playwright/integrated-*.png`; generated browser evidence is deliberately excluded from this source-only series and PR.
