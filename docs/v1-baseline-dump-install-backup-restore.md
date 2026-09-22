# V1 baseline-dump install, backup, and restore contract

**Status:** Owner-directed implementation target. This document records the
baseline-first contract and the gap between it and the current backup code; it
does not assert that the complete workflow is implemented or tested.

## Governing rule

Build an installer or backup from the **currently tested running baseline**.
Historical migrations are not the installation or recovery mechanism. A fresh
installation imports the complete table definitions and required fixed seeds
from a pinned SQL dump. A backup carries the complete database snapshot,
including table definitions and records, plus the required private assets and
configuration in an authenticated encrypted package. Restore imports that
snapshot into an empty, supported target. The application and restored database
must describe the same tested baseline.

This is a snapshot contract, not a request to run old migration chains, rebuild
history, or infer the current schema from yesterday's source. Migrations may
remain as development or exceptional compatibility tooling, but they are not
normal installer/backup/restore prerequisites.

## Fresh installer

1. Freeze the accepted code and schema after functional human testing. Record
   the source commit, application version, supported MySQL range, schema dump
   SHA-256, package digest, and release identity. A changed schema needs a new
   reviewed baseline; do not silently regenerate an old released artifact.
2. Produce a complete SQL schema dump from that baseline, including tables,
   constraints, indexes, triggers, and fixed seed rows. Do not include user or
   historical application records in a fresh installer.
3. Verify the package and dump digests, database version, permissions, and that
   the selected target database is empty. Import the dump exactly once.
4. Mint target-specific installation identity and initial owner credentials
   after import. Do not insert fabricated historical migration rows.
5. Verify schema inventory, fixed seeds, identity, health, login, and the
   operator's key workflow before declaring the installation ready.

The current `schema/mysql84/schema.sql` and `baseline.json` already serve as a
pinned schema artifact; `BaselineInstaller` applies them on a proven-empty
target and mints identity. On 2026-09-22, direct SQL import into an isolated
MySQL 8.4 database yielded 48 tables, 3 triggers, 2 fixed roles, and zero
historical migration rows. That structural proof is not a complete UI or
backup/restore acceptance test.

For the **next** reviewed baseline, `scripts/generate-baseline-from-database.php`
accepts `--source-kind=baseline` against a quiesced, tested MySQL 8.4 baseline
database. It verifies installation identity, fixed roles, and an empty legacy
migration ledger, then emits `schema.sql` and `baseline.json` to an isolated
output directory. The generation must be repeated with identical bytes, and
the emitted dump must be imported into a fresh empty target before review.
The default `--source-kind=legacy` remains for historical reproduction only.
Generated output is a candidate, not authority until reviewed and pinned in a
release. The existing pinned dump is not silently replaced by this process.

## Backup builder

1. Accept only a healthy source whose code, schema, installation identity,
   and runtime are compatible with the declared baseline. Bind the backup to
   that exact baseline and record a manifest of database dump, asset, and
   configuration digests.
2. Capture a transactionally consistent **full database dump**: definitions,
   constraints, triggers, fixed seeds, and every table's records. Preserve
   relational IDs and sequence state. Do not silently omit tables according to
   the old 28-durable/17-reset/3-target-local policy.
3. Include required uploaded/private assets and secret material in the
   authenticated encrypted envelope. Never log or expose secrets in a public
   artifact, source repository, or browser download URL.
4. Keep the dump encrypted and integrity-protected at rest and in transit.
   Verify the archive manifest and all member digests before any target import.

A full snapshot can contain sessions, authorization codes, tokens, outbox
records, and other live authority. A staged restore must therefore remain
isolated, with workers, delivery, OAuth, and Realtime disabled until a human
chooses whether to resume, rotate, invalidate, or regenerate that state. This
decision is explicit and audited; it is not a hidden row omission or automatic
replay. A backup taken while writes continue must use a proven consistent
database snapshot and a coordinated asset capture, or fail closed.

## Restore

1. Require an empty supported target and explicit operator confirmation. Do
   not overwrite the serving database or assets, change routing, or cut over
   automatically.
2. Authenticate and inspect the backup before import. Check version,
   provenance, schema/record inventory, required assets, configuration, and
   available storage. Reject unknown, incomplete, or incompatible archives.
3. Import the contained SQL snapshot into the empty database, restore assets
   and protected configuration into staging, then compare row counts, schema
   objects, IDs/sequences, digests, and application identity against the
   manifest. Do not replay historical migrations.
4. Start only the isolated application for acceptance. Verify login and the
   actual administrator workflow, project/message history, file access,
   authorization, notification behavior, and recovery actions. Keep outbound
   workers disabled until the operator approves the authority/replay policy.
5. Promote only through a separate, documented cutover with a rollback plan
   covering code, database, configuration, and assets together.

For the existing older deployment, make a protected copy first. Adapt that
copy to the chosen tested codebase, validate its behavior, then **dump the
resulting baseline** for package and recovery tests. Never modify the serving
database to prove this contract.

## Current implementation gap and acceptance gate

`scripts/create-encrypted-backup.php` and `BackupProducer` currently generate
an encrypted **data-only** NDJSON envelope, retaining selected durable rows
while resetting or excluding other tables. `scripts/restore-encrypted-backup.php`
and `StagedBackupRestore` require a separately installed target schema. That
remains a protected legacy recovery path, not proof of the owner-directed full
dump contract. Do not label the current backup UI, release, or installer as
full-snapshot capable until the producer and restore path are implemented and
tested end to end.

`FullSnapshotSql` is an in-progress SQL component for that new path. It writes
all baseline tables and records, creates triggers after rows, and refuses a
nonempty import target. Its isolated MySQL 8.4 component test is **not** an
encrypted package, asset/config restore, live-copy proof, or application-ready
acceptance result. The old package validator intentionally rejects `.sql` in
data-only backups; the full-snapshot package needs a distinct versioned
manifest/validator rather than weakening that format in place.

The separate full-snapshot transport uses the existing authenticated
`BackupEnvelope` with a strict `syndicatum-full-snapshot` manifest (version
`1.0`). Its archive has exactly `database/snapshot.sql`,
`secrets/recovery.json`, and sorted declared `assets/avatars/<digest>.<type>`
members. The manifest records creation time, application/baseline/schema head,
source commit and installation ID, source database and MySQL version, SQL
table/trigger counts and per-table row counts/digests, and each member's SHA-256 and
byte length. No secret value appears in the plaintext manifest. The encrypted
recovery document retains the existing closed secret classifications.

`scripts/create-full-snapshot.php` requires explicit private output/staging/
avatar paths and operator confirmation that source writes/assets are quiesced.
`scripts/restore-full-snapshot.php` requires an explicit host,
port, database, user, target-asset directory, and exact empty-target approval;
the target password comes from an environment variable, not command arguments.
The importer authenticates and validates every declared member before database
import, requires an empty database and asset directory, and never cuts over.
The producer and importer verify private-stage cleanup before reporting success.
This is an isolated technical path, **not yet** a production-ready Admin UI or
app/worker/human-workflow acceptance result.

Acceptance requires: exact package provenance; repeatable fresh import from
the pinned dump; encrypted full-snapshot creation; empty-target restore without
migration replay; schema, row, asset, secret, and sequence verification;
deliberate handling of replayable credentials/events; failure recovery; and
human workflow testing on the restored instance. Syntax tests, CI, and schema
parity alone are insufficient.
