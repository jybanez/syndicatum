# V1 legacy live-baseline uplift proposal

**Status update (2026-09-22):** Archived compatibility analysis, not the active
installer/backup/restore plan. The owner-directed path is to adapt a protected
copy to the current tested codebase, validate it, then export/import a complete
baseline dump as described in `docs/v1-baseline-dump-install-backup-restore.md`.
Do not run this proposal's migration-ledger uplift as the normal deployment
or recovery workflow.

**Status:** Draft compatibility contract; not authorization to change the serving instance.

## Purpose and boundary

The existing internal Syndicatum deployment contains user, project, message,
agent, and coordination history. Its database is a migration-built pre-V1
installation, not a fresh installation from the V1 MySQL 8.4 baseline. The
current V1 release candidate supports fresh installs only. This proposal
defines a separate, truthful path to bring that existing deployment to the
current tested codebase before using its installer and recovery workflows for
human acceptance. It does not reinterpret the immutable fresh-install package
or authorize live deployment.

The serving checkout and database must remain unchanged until a disposable
clone passes the full acceptance gate. Do not point a fresh installer at the
existing database, delete historical migration rows, stamp a fresh-install
identity on legacy data, or replay the entire migration chain.

## Observed compatibility boundary

A consistent logical dump of the serving MySQL 5.7 database imported into an
isolated MySQL 8.4.11 container without SQL errors. The snapshot has 45 tables
and 25 applied historical migrations through
`202609170001_message_request_fingerprint`. The protected V1 baseline has 48
tables and no migration rows on a *fresh* installation.

The required forward migrations are:

| Migration | Forward change | Data treatment |
| --- | --- | --- |
| `202609180001_delivery_worker_heartbeat` | Add `delivery_worker_heartbeats` | New operational table; no historical rows synthesized. |
| `202609180002_realtime_outbox_attempt_diagnostics` | Add attempt timestamp and failure code to `message_events_outbox` | Existing rows retain null diagnostics. |
| `202609180002_responsibility_events` | Add `responsibility_events` and responsibility-generation columns | Append-only table starts empty; existing participants receive generation 0; old addressees retain null generation. |
| `202609180003_delivery_path_failure_categories` | Add attempt timestamp and failure code to three delivery queues | Existing rows retain null diagnostics. |
| `202609180004_delivery_terminal_timestamps` | Add terminal timestamps and normalize dead-delivery timestamps | Preserve known attempt/delivery/creation evidence in that order; do not queue or replay deliveries. |

On the isolated clone, applying those five migration definitions preserved the
snapshot's business-data row counts. Its columns, indexes, foreign keys and
delete/update rules, table engines/collations, and three application triggers
then matched the fresh V1 schema apart from installation identity. One further
constraint differs because MySQL 5.7 ignored it: the V1
`chk_project_participants_identity` CHECK requires a human participant to have
only `user_id` and an agent participant to have only `agent_id`. All observed
snapshot participants satisfied it, and it was added successfully on the
clone. A production upgrader must validate and add it explicitly, failing
closed if later live data violates it.

The remaining schema object is `syndicatum_installation_identity`, including
its singleton CHECK. This cannot be created as if the deployment had been
freshly installed from the V1 baseline.

## Legacy-lineage identity

Use the existing identity fields to represent the *target* package and release
truthfully: `application_version`, `schema_baseline`, `schema_head`,
`baseline_source_commit`, `release_source_commit`, `package_sha256`, and
`package_format_version` must match the exact verified package that is actually
deployed. Generate one installation UUID. For this source state, the four
`last_upgrade_*` fields form an all-or-none lineage marker:

| Field | Proposed upgraded-instance value |
| --- | --- |
| `last_upgrade_id` | `legacy-v1:` followed by a unique UUID; distinct from an ordinary released-version upgrade. |
| `last_upgrade_from_version` | Exact source migration head, `202609170001_message_request_fingerprint` (40 characters, fitting the existing column). |
| `last_upgrade_to_version` | Target application version, `1.0.0`. |
| `last_upgraded_at` | Actual UTC completion timestamp. |

The preserved migration ledger supplies the full source history and checksums.
For a legacy adoption, `installed_at` records when a trusted installation
identity was first established, **not** an invented original deployment date.
The upgrade receipt must record the source checkout commit, source schema head,
dump checksum, target package identity, and run ID outside the database as
operational evidence; never place credentials in it.

Migration checksums are byte-sensitive. All 25 observed ledger hashes match
the serving LF migration files and the protected source after LF
normalization. A Windows CRLF checkout has different raw hashes. Rehearsal and
release execution must use exact canonical package migration bytes or an
explicitly verified canonical-blob comparison. Do not disable checksum
validation or silently accept arbitrary edited migration files.

The fresh-install readiness invariant stays unchanged: a fresh baseline
identity has zero historical migration rows. A legacy-upgraded readiness path
must instead require the explicit `legacy-v1:` upgrade marker, the expected
historical migration rows and checksums, the current schema head, the exact
schema/constraint shape, and a valid package identity. Unknown or partial
lineage must fail closed. A second run against an already-upgraded database
must return a verified no-op, not replay migrations or replace identity.

The current protected-main implementation does **not** yet provide that path.
`InstallationState::inspect()` reads only the first nine identity fields and
compares the total migration-row count to `post_baseline_migrations` (zero in
this release). A truthfully marked legacy installation with its preserved
history would therefore fail readiness. `SchemaMigrator::migrate()` returns
without checking that history when an identity table exists and no
post-baseline migrations are declared. The upgrade implementation must add a
separate lineage-aware readiness and checksum-verification branch; inserting
an identity row alone is not an upgrade. `BaselineInstaller` must remain
fresh/empty-database-only.

## Rehearsal and human acceptance

1. Start from a consistent source snapshot and logical dump in a disposable,
   network-isolated clone. Verify both checksums before use.
2. Restore into a separate MySQL 8.4 target. Keep delivery workers, Realtime
   publishing, outbound webhooks, and other external effects disabled.
3. Validate the 25 source migration rows/checksums, schema head, preconditions,
   participant identities, and durable row counts before mutation.
4. Apply only missing forward migrations, preserving existing ledger rows and
   adding records only for newly applied migrations. Add the participant CHECK.
5. Install the legacy-lineage identity from the exact target package, then
   verify readiness and full schema/constraint parity. Repeat on a fresh clone
   and rerun against an already-upgraded clone to prove deterministic,
   idempotent or fail-closed behavior.
6. With external delivery still disabled, verify administrator login, existing
   users/projects/timelines/messages/agents/participants, coordination history,
   and the current navigation including **Backup / Restore**. Verify recovery
   operations see the trusted identity and do not replay stale outbox work.
7. Run the owner workflow on the clone: create an encrypted backup, inspect it,
   and stage it in another empty MySQL 8.4 target. Confirm that stage-only
   restore does not overwrite the serving clone or claim production cutover.

Before a live cutover, reconcile intentional changes in the current dirty
checkout with protected main. Preserve local configuration and runtime
artifacts outside the release package. Record the exact source/DB/asset
snapshot, quiesce writes and workers, take a final consistent backup, and
document how to restore the prior code, database, config, and assets together.
Do not deploy while the current live MySQL 5.7 vs target MySQL 8.4 hosting path
or any material behavior conflict remains unresolved.

## Source reconciliation already identified

The serving checkout is not simply an older clean revision. Its modified
`index.php`, `assets/app.mjs`, `assets/app.css`, and surface tests contain an
intentional unified workspace/timeline experience absent from protected main,
including the current collapse/expand interaction. Replacing those files with
protected-main versions would regress a tested human workflow. Conversely,
protected main contains the Backup / Restore surface and newer authentication
and responsibility behavior absent from those live files. The integration
target must preserve the live workspace experience while bringing in the V1
admin/recovery and backend capabilities; neither side can be copied wholesale.

The serving Helper bundle/loader uses newer icon, navigation, splitter, and
timeline component revisions than protected main. Treat this as a dependency
integration requiring component-level contract tests, not as disposable build
noise. The live Companion is older than the protected-main 0.10.1 fixes and
must not overwrite them. Existing local proposal documents remain in the
top-level `/docs` area and need review before any publication to main; local
runtime/output/cache files do not belong in a release package.

## Explicit non-goals

- Changing the fresh V1 install contract or claiming the existing deployment
  is a supported prior V1 release.
- Using the mutable live filesystem as a trusted clean-release producer.
- Treating schema parity alone as login, delivery, recovery, or owner-workflow
  acceptance.
- Mutating the serving checkout, database, routing, or production workers as
  part of disposable-clone analysis.
