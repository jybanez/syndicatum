# V1 baseline-only installer impact map

**Status update (2026-09-22):** Historical transition inventory. The active
baseline-dump install/backup/restore contract is
`docs/v1-baseline-dump-install-backup-restore.md`. References below to
post-baseline migration handling are compatibility context, not a requirement
for normal fresh installation or restoration.

Status: pre-implementation evidence for the open canonical-release-producer
gate. This map records every currently identified path that creates, infers, or
tests installation/schema state before fresh-install behavior changes.

The new fresh-install path is exclusively:

`empty MySQL 8.4 database -> authoritative baseline -> installation/schema identity -> initial owner/admin -> health verification`.

It must not call the legacy embedded-schema builder, replay historical
migrations, or synthesize migration-history rows.

## 1. Replaced for fresh installation

| Component / call site | Current assumption | Replacement boundary |
| --- | --- | --- |
| `ChatRepository::installSchema()` in `src/ChatRepository.php` | Creates six legacy chat tables from embedded SQL, runs compatibility column repairs, then invokes every historical migration. | New `BaselineInstaller` applies the committed baseline to a proven-empty supported database, writes separate installation/schema identity, and invokes only declared post-baseline migration handling. Fresh install never calls `installSchema()`. |
| `scripts/chat-db.php install-schema` | Public CLI adapter to the legacy embedded-schema + replay path. | A new explicit baseline-install command receives trusted baseline paths/identity and refuses a non-empty target. The old command remains visibly legacy and is never used by release fresh-install acceptance. |
| `docker/entrypoint.sh` | Every container startup runs `chat-db.php install-schema`, mixing initialization, repair, and historical replay. | Runtime startup becomes state-aware: an empty database requires the explicit packaged baseline-install/bootstrap path; an installed database may run post-baseline upgrade checks only. It cannot create a release baseline from source history. |
| `api/claim.php` lazy `installSchema()` fallback | A request can create/upgrade schema implicitly when core tables are absent. | Fresh installation cannot originate from an API fallback. The endpoint fails closed as not installed until the authorized installer completes. |
| `api/install-schema.php` | Historical endpoint is already disabled with HTTP 410. | Reuse its fail-closed state; the baseline installer is not reintroduced as an unauthenticated web action in this slice. |
| Database-backed tests calling `ChatRepository::installSchema()` as fresh setup | Test fixtures prove the legacy replay path rather than the packaged baseline path. | New baseline-installer and package acceptance fixtures own fresh-install proof. Feature tests may use a dedicated test-schema helper, but cannot be cited as V1 fresh-install evidence. |
| `tests/migrations.php` initial-install assertions | Treats one row per historical migration as proof that installation is current. | Split baseline-install assertions from legacy-upgrade assertions. Baseline install records no fabricated historical rows and proves head/cutover through installation identity. |
| Docker clean-install stages in `scripts/docker-acceptance.ps1` | Build and boot the source tree, then depend on entrypoint replay. | Canonical-package acceptance consumes the exact extracted ZIP and baseline artifact, installs an empty pinned MySQL 8.4 database without historical replay, and then runs health checks. |
| MySQL 8.4 and release workflows that package source with `git archive` | Broad source archive is treated as the install candidate. | One protected Linux producer emits the allowlisted canonical ZIP and sidecars from immutable Git blobs; all install jobs consume that uploaded artifact. |

## 2. Retained only for upgrades or legacy compatibility

| Component | Retained responsibility | Required guardrail |
| --- | --- | --- |
| `SchemaMigrator` | Verifies historical checksums for legacy deployments and applies future declared post-baseline migrations. | On a baseline-installed database, it skips every migration at or before normalized cutover `202609180004` without inserting synthetic rows and considers only trusted post-baseline inventory. |
| `migrations/*.php` through `202609180004_delivery_terminal_timestamps` | Historical upgrade and schema-derivation evidence. | Excluded from fresh-install payload/replay. The normalized cutover maps only to the full final historical identity. |
| `scripts/mysql57-to-84-migration-acceptance.ps1` | Proves the separately supported internal/legacy transition. | Cannot be used as evidence for a clean V1 baseline installation. |
| Migration-status CLI and checksum tests | Diagnose legacy installations and future upgrade eligibility. | Reports baseline identity/head separately from historical migration rows; absence of fabricated pre-cutover rows is valid for baseline installs. |
| Compatibility column repair helpers reached from legacy install | Supports existing pre-baseline databases. | Never run as part of the authoritative empty-database baseline application. |

## 3. Reused unchanged where practical

| Component | Reused responsibility | Evidence needed |
| --- | --- | --- |
| `Db::pdo()` and database configuration parsing | Connect to the explicitly selected target database. | Target must be proven empty and MySQL 8.4-compatible before baseline mutation. |
| `AuthService::bootstrapAdministrator()` | Creates the initial owner/admin under its existing locking and uniqueness rules. | Called only after baseline and installation identity succeed; failure cannot mark installation complete. |
| `BaselineMetadata` validation | Validates baseline ID/head, MySQL compatibility, table policies, and post-baseline inventory. | Metadata must exactly cover the generated live table inventory and schema digest. |
| `InstallationIdentity` value object | Keeps package/baseline/head identities distinct in presentation contracts. | Extend the identity model and add protected persistence for baseline-freeze and release-source commits without collapsing them into package digest or schema head. |
| `PackageManifest`, `ArchiveSafetyReader`, and controlled extraction | Validate and stage the exact canonical artifact. | Fresh-install acceptance consumes only the staged, ordinary-reader-accepted package with no producer bypass. |
| `api/v1/health.php`, application health, and authorization services | Verify the installed packaged runtime after administrator bootstrap. | Extend health to require expected baseline/head and no pending trusted post-baseline migrations; evidence binds to installation identity and package SHA-256. |
| Normal application repositories and feature tests | Exercise business behavior against an already prepared schema. | Their setup mechanism is not itself release fresh-install evidence unless it uses the packaged baseline route. |

## 4. Deprecated but temporarily preserved

| Component / behavior | Temporary reason | Removal or isolation condition |
| --- | --- | --- |
| `ChatRepository::installSchema()` | Existing tests, local development, legacy environments, and current Docker boot still call it. | Mark and route as legacy-only once baseline installer tests are green; remove from runtime fresh-install callers before producer acceptance. |
| `scripts/chat-db.php install-schema` legacy command | Operational compatibility during transition. | Rename or emit an explicit legacy warning; canonical-package workflows must reject its use. |
| Docker entrypoint automatic schema mutation | Existing source-tree acceptance depends on it. | Replace before canonical package empty-install evidence; ordinary steady-state startup must not replay project history. |
| `api/claim.php` implicit schema creation | Legacy convenience behavior. | Remove before external V1 install flow; an uninstalled system must fail closed. |
| `setup.php` installation preview | UI-first placeholder intentionally performs no installation. | Preserve as preview-only in this producer slice; do not turn it into a web installer before backend acceptance and later UI authorization. |
| Broad `git archive` tarball creation in contract/release workflows | Existing RC/Docker evidence consumes it. | Superseded for publication once canonical ZIP jobs are accepted; retained artifacts are historical evidence only. |
| Source-tree Docker acceptance | Continues regression coverage during transition. | Cannot publish or attest a canonical release; canonical-package adapter acceptance consumes the uploaded ZIP. |

## Hidden-replay audit queries

Acceptance reruns source searches for `installSchema`, `install-schema`,
`SchemaMigrator`, `syndicatum_schema_migrations`, `migration-status`, Docker
entrypoints, and source-archive builders. Every resulting runtime or CI call site
must remain represented in this map. An unclassified new caller fails the
baseline/installer review.

## Required transition evidence

- generated and committed MySQL 8.4 baseline SQL and validated metadata;
- deterministic regeneration and drift comparison;
- exact live-table coverage and schema digest comparison;
- a proven-empty database guard;
- separate installation identity values for application version, baseline ID,
  schema head, baseline-freeze commit, release source commit, and package digest;
- baseline install with zero historical migration rows;
- post-install migrator evidence showing only post-cutover consideration;
- initial administrator bootstrap and health verification;
- a negative assertion that the legacy embedded/replay path was not invoked;
- a canonical-package acceptance job that consumes the exact uploaded artifact.
