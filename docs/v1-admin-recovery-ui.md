# V1 administrator recovery UI

Status: implementation candidate. The accepted encrypted-backup and stage-only
restore backend remains authoritative; this UI does not add live cutover.

## Operator-visible actions

- **Get clean package** authorizes a one-time download only when an immutable
  CI-built release ZIP is mounted outside the public web root and its bytes match
  `SYNDICATUM_PACKAGE_SHA256`. The runtime has no release-builder entry point.
- **Build encrypted backup** invokes the accepted `BackupProducer` with a
  server-generated, private, no-replacement destination. The browser supplies
  no path or producer option. The result is an authenticated encrypted envelope,
  not executable application code.
- **Restore** uses the native Helper file uploader to submit one encrypted
  `.syndicatum-backup` file. Inspection runs the exact authenticated envelope,
  manifest, ordinary archive-reader, controlled-extraction, portable-secret,
  baseline, table-policy, row-shape, and empty-target checks without mutation.
  A second native Helper form modal shows the immutable inspection summary and
  requires two acknowledgements plus the exact phrase `STAGE RESTORE`.

## Hard boundaries

- Administrator session and CSRF validation are required before constructing a
  mutating recovery service.
- Recovery status remains readable when a legacy/internal installation lacks the
  trusted installation-identity row, but reports that identity and backup action
  as unavailable; backup creation still fails closed until the row exists.
- Backup and staged-restore requests use idempotency keys. A network interruption
  is reconciled with the same key and bounded status polling; the UI does not
  silently create a new action. A receipt left in `started` across a server
  restart becomes `RECOVERY_OUTCOME_UNKNOWN` after 15 minutes and requires
  explicit target/artifact inspection rather than being reported as success.
- One global non-blocking recovery lock prevents overlapping backup/restore work.
- Operation receipts, retained encrypted inspections, and one-time download
  tickets live in private storage with restrictive permissions.
- Download tickets are random, actor/session-bound, expire after five minutes,
  and are consumed once. The artifact SHA-256 is rechecked before streaming. A
  successful backup operation can authorize another one-time ticket after an
  expired or interrupted download, after rechecking the retained artifact.
- Encrypted backup artifacts are rotated after seven days and bounded to ten
  retained files / 10 GiB. Rotation never removes an artifact protected by an
  active download ticket and fails closed when at least 1 GiB is not free.
- The restore target is server-configured. Browser requests cannot supply a DSN,
  credential, filesystem path, or cutover option.
- The target's MySQL server UUID and database identity are compared with the
  serving connection. The serving database is rejected even when an alias is
  used. The trusted baseline, exact columns, target-local seeds, empty durable
  and reset tables, and pristine sequence state must all pass.
- Restore inspection deletes every decrypted/extracted stage. Only the encrypted
  candidate is retained for one hour. At most four retained candidates totaling
  1 GiB are allowed; quota admission, upload copying, and retention are atomic
  under the recovery lock, and abandoned partial uploads expire after one hour.
  Staging re-hashes and re-authenticates the retained candidate.
- A stage receipt always records `cutover_performed=false`,
  `live_overwrite=false`, and `automatic_cutover=false`.
- A failed or uncertain staged restore makes the target disposable. It must be
  reprovisioned before another inspection because non-transactional sequence
  state may have changed even when row inserts rolled back.

## Configuration

The container already supplies `SYNDICATUM_BACKUP_DIR`,
`SYNDICATUM_STAGING_DIR`, `SYNDICATUM_AVATAR_DIR`, and
`SYNDICATUM_BACKUP_KEY_FILE`.

Optional clean-package retrieval:

- `SYNDICATUM_RELEASE_PACKAGE_PATH` — immutable, read-only mounted CI ZIP; the
  canonical `.manifest.json` and `.provenance.json` must be mounted beside it;
- `SYNDICATUM_RELEASE_TAG` — exact protected annotated release tag;
- `SYNDICATUM_RELEASE_PROVENANCE_SHA256` — pinned SHA-256 of the canonical CI
  provenance JSON mounted beside the ZIP;
- `SYNDICATUM_RELEASE_REPOSITORY` — exact GitHub `owner/repository` recorded by
  the protected tag workflow (defaults to `jybanez/syndicatum`);
- `SYNDICATUM_PACKAGE_SHA256` — pinned full artifact digest;
- `SYNDICATUM_RELEASE_SOURCE_COMMIT` — full source commit.

Required to enable Restore:

- `SYNDICATUM_RESTORE_DB_HOST`
- `SYNDICATUM_RESTORE_DB_PORT` (defaults to `3306`)
- `SYNDICATUM_RESTORE_DB_NAME`
- `SYNDICATUM_RESTORE_DB_USER`
- `SYNDICATUM_RESTORE_DB_PASS`

The staging target must be separately provisioned from the trusted MySQL 8.4
baseline and contain only the baseline's target-local seeds. No automatic target
creation, overwrite, database drop, traffic switch, or asset cutover is present.

## Canonical Helper components

The workflow retains `ui.tabs`. Backup and final staged-restore confirmation use
the complete declarative `ui.form.modal`, including required fields, native
validation, managed busy state, focus trapping, focus restoration, and duplicate
submission prevention. Restore file selection uses `ui.file.uploader` inside
`ui.action.modal` because the canonical form modal has no generic file field or
arbitrary component slot. Verified receipts use `ui.data.inspector`; upload
progress and cancellation are supplied by the uploader's built-in progress API.
