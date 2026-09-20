# Syndicatum V1 Canonical Package, Web Installer, Backup, and Restore Proposal

**Status:** Owner-approved on 2026-09-20; implementation authorized UI-first with non-deceptive placeholders and Helper-first composition
**Prepared:** 2026-09-20
**Architecture reference:** protected `main` at `f7e9497d0d08955c3bcc6fc35085725429f46bfd`
**Decision record:** Syndicatum messages #2930, #2931, and #2933
**Companion UI specification:** `docs/v1-package-installer-admin-ui-proposal.md`

## Executive summary

Syndicatum should be distributed as one canonical, versioned application package. Docker should be one verified way to host that package, not the definition of the application or its primary installation contract.

The canonical clean artifact will be a deterministic ZIP produced only by trusted CI from an exact protected tag. It will contain the production application, a validated MySQL 8.4 schema baseline, a database-independent web installer, package metadata, integrity hashes, and the limited documentation needed to install the release. A fresh installation will apply the current baseline directly and will not replay migrations from the beginning of the project.

The administrator will see one **Backup / Restore** surface with three simple actions:

1. **Get clean Syndicatum package** — retrieve the CI-produced canonical package for an allowed release.
2. **Build backup package** — create an authenticated-encrypted, non-executable recovery package from the running instance.
3. **Restore backup package** — validate and restore a recovery package into an empty or staged target, then verify it and provide an explicit cutover procedure.

Internally, this is one package contract and one package reader with two trusted producers:

- clean release producer: trusted release CI only;
- backup producer: an authenticated administrator on a running instance.

Minimum V1 will not overwrite a live installation during restore. It will not produce an unencrypted recovery package. It will not allow a backup to introduce executable application code. It will not allow the first visitor to an uninstalled site to claim ownership. It will not rewrite the immutable `v1.0.0-rc.1` / MySQL 5.7 evidence.

The recommended owner decision is **go**, authorizing implementation in the phases defined below. Until Jonathan approves this proposal, implementation and the external-host Docker gate remain paused.

## Why change direction

The existing Docker work proved important properties: exact-source packaging, checksums, MySQL lifecycle behavior, migration behavior, backup/restore mechanics, security inventory, and reproducible CI. It also revealed that treating Docker as the application-level installation contract makes direct PHP hosting, managed databases, migration between hosts, and product-owned recovery unnecessarily difficult.

A canonical application package provides these product benefits:

- one artifact can be downloaded, mirrored, inspected, checksummed, and installed without requiring Docker;
- traditional PHP hosting, a cloud VM, managed MySQL, and a future Docker image can use the same application bytes and baseline schema;
- fresh installation becomes short and deterministic rather than dependent on the entire historical migration chain;
- release provenance becomes a property of the application package rather than a particular container build;
- backup and restore can use the same version and compatibility vocabulary as installation and upgrade;
- Docker-specific logic becomes thinner and easier to verify;
- future deployment adapters can be added without creating new application builds.

This is a change to the subsequent V1 release chain. It does not alter or reinterpret the immutable RC1 evidence.

## Current state and proposed state

| Concern | Current protected-main behavior | Proposed V1 behavior |
|---|---|---|
| Release artifact | Exact-commit `git archive` tarball tested primarily through Docker | Deterministic production-only ZIP built by trusted CI |
| Fresh database | Legacy base schema followed by every historical migration | Current release baseline applied directly |
| Upgrade database | Ordered migrations with checksum validation | Only migrations after the installed released baseline |
| First-run experience | Operator CLI and environment configuration | Database-independent, ownership-protected web installer plus headless adapter |
| Configuration | Environment variables or an external PHP secrets file | Explicit config provider: protected file for traditional hosting; environment/secrets for Docker |
| Database placement | Compose-managed local database is the primary documented path | Local, remote, dedicated, or managed MySQL 8.4 |
| Backup/restore | Docker acceptance proves a logical dump and restore | Product-level encrypted backup and staged restore contract |
| Persistent files | Avatar directory mounted in Docker | Registered persistent-asset set, initially avatars |
| Docker | Primary installation and acceptance model | Downstream adapter consuming the exact canonical ZIP |
| GitHub release | Source-style tarball, checksum, provenance | Canonical ZIP, detached checksum, manifest, and provenance |

## Architectural principles and non-negotiable boundaries

### One contract, one reader, two trusted producers

Clean releases and backups share a versioned package envelope, parser, compatibility vocabulary, integrity rules, and validation library. They are not interchangeable payloads.

- `package_kind=release` may contain allowlisted executable application files and a schema baseline. Only trusted CI may produce it.
- `package_kind=backup` may contain data, registered persistent assets, recovery metadata, and encrypted portable secrets. It must never contain PHP, JavaScript, shell scripts, binaries, or other executable application code.

The reader rejects a package whose declared kind does not match its allowed layout.

### A live instance is not a release authority

The administrator action **Get clean Syndicatum package** retrieves or references the verified canonical package associated with the installed or allowed target release. It does not rebuild application code from the mutable live filesystem.

### Fresh install never replays project-history migrations

Each supported release line has a named schema baseline. A fresh install applies the current baseline directly. Historical migrations before that baseline remain in source control as historical and upgrade evidence, but are not executed by the fresh installer.

### Backups are confidential and non-executable

A data-bearing backup requires authenticated encryption. There is no unencrypted recovery-package mode in minimum V1. A future redacted export, if useful, must be a different package kind and security contract.

### Restore is staged, not self-overwriting

Minimum V1 restores into an empty database/instance or a separately staged database. It verifies the result and presents an explicit cutover procedure. Destructive in-place restore and automatic rollback are deferred.

### Installer ownership is established out of band

An uninstalled public URL cannot grant ownership to its first visitor. Installer authorization comes from a secret or proof placed by the filesystem/environment operator, outside the public application tree.

## Canonical clean package specification

### Artifact set

For release `1.0.0`, trusted CI produces:

- `syndicatum-1.0.0.zip` — canonical application package;
- `syndicatum-1.0.0.manifest.json` — trusted sidecar manifest for the ZIP payload;
- `syndicatum-1.0.0.zip.sha256` — detached whole-package checksum;
- `syndicatum-1.0.0.provenance.json` — source, workflow, and build identity;
- release notes and applicable notices.

Optional signing may be added later. V1 integrity is based on protected release automation, a detached SHA-256, per-file hashes, and retained provenance.

The ZIP cannot contain its own ZIP checksum because that would be self-referential. The manifest is also a trusted sidecar rather than an entry inside the ZIP: every ZIP entry is therefore payload and must appear exactly once in the manifest, with no embedded-manifest exception. The sidecar manifest contains hashes of the content tree; the detached checksum covers the exact final ZIP bytes. Protected provenance binds the sidecar manifest, checksum, release identity, and trusted operation context.

### Archive validation and extraction boundary

The V1 reader validates without extracting. It first inspects the raw ZIP structure and rejects unsupported ZIP features, local/central-header disagreement, duplicate or case-aliasing names, preambles, gaps, overlaps, aliased local records, trailing bytes, unsafe paths, non-regular UNIX file types, unknown permissions, excessive entry counts or sizes, and per-file or aggregate compression ratios above the fixed limits. It then requires the actual archive inventory to equal the trusted sidecar manifest exactly, streams each entry to verify its declared byte size and SHA-256, derives namespace roles from actual paths, and reconstructs the canonical content-tree digest from verified facts.

Release validation is bound to trusted source commit, tag, baseline, and schema head. Backup validation is bound to a trusted recovery catalog; backup metadata uses a closed non-authoritative schema, cannot carry DDL or recovery policy, and executable signatures or executable-role extensions fail closed. Validation executes no SQL and mutates no application state.

Parsing is not trust. The exact sidecar-manifest SHA-256 must match protected provenance/context before its inventory becomes authoritative, and canonical releases likewise require the expected whole-archive SHA-256. A future uploaded backup has no independently pre-known archive hash; its exact archive and sidecar identities must therefore come from a successfully authenticated encryption envelope before restore authority is granted. A structural/content validation report is evidence only and must never be treated as equivalent to authenticated release or restore authority.

Trusted backup context separates required payload paths from optional allowed paths. V1 requires exactly `metadata/recovery.json` plus at least one logical-data file, which may be zero bytes for a legitimately empty table. Optional persistent assets are permitted only when the manifest presence flag matches their actual roles. Removing a required path from both archive and manifest does not make an incomplete backup valid. Asset signature checks are only type-prefix screening, not full media decoding; restored asset bytes remain untrusted, non-executable data for downstream serving.

Opening or validating an archive never extracts it. Controlled extraction is a separate, explicit future operation that may run only after full validation into a private staging directory. A validation result is evidence, not a reusable authorization token: extraction must re-establish the trusted archive identity and prevent path races before writing any file. Release and backup producers remain deferred until this reader contract and its adversarial fixtures are accepted.

V1 controlled extraction is restricted to a trusted POSIX staging filesystem outside the public web root; Windows continues to support validation but fails closed for extraction because PHP cannot prove private NTFS ACLs or safely exclude hostile reparse-point races. The canonical staging path and every ancestor up to the filesystem root must be real directories owned by root or the extraction process user; non-sticky group/world-writable ancestors are rejected so another UID cannot rename the private leaf and replace it with a public-root symlink. Extraction validates and streams from one immutable snapshot/session, exclusively creates a random `0700` stage, rechecks file and whole-archive hashes, forces backup files to `0600`, and cleans only internally recorded paths on failure. Its result grants staging access only, never install, restore, SQL, promotion, or cutover authority.

Independent closure evidence at `4ba1cae` uses an explicitly verified same-parent adversarial probe. On the old `b2cf3f3` code, UID 65534 can rename a UID 33-owned `0700` leaf within a non-sticky writable parent and replace the old pathname with a public-root symlink; corrected preflight rejects that ancestor chain before snapshot or stage creation. The trusted root/service-owned `/var/lib/syndicatum/staging` chain and a service-owned private directory beneath root-owned sticky `/tmp` both pass. This corrected evidence supersedes the earlier cross-parent wording from Syndicatum #2998, whose first harness did not verify every UID transition. Assessor closed the controlled-extraction gate in #3005 after Helper's independent #3004 rerun and exact-head CI run `35471353005` passed all required jobs.

### Required manifest fields

`manifest.json` contains at least:

- package contract name and `format_version`;
- `package_kind` (`release` or `backup`);
- Syndicatum application version;
- exact source commit and protected tag;
- schema baseline identifier and schema head;
- deterministic source timestamp;
- compatible PHP range and required extensions;
- compatible MySQL range and required SQL mode/charset/collation;
- minimum package-reader version;
- supported upgrade source releases/baselines;
- flags describing whether data and registered persistent assets are present;
- ordered allowlisted file inventory with size and SHA-256;
- content-tree digest;
- provenance reference.

Unknown package-format major versions are rejected. A reader may ignore explicitly optional fields introduced in a compatible minor version, but never ignores a new package kind, required capability, or unknown security rule.

### Canonical content-tree byte contract

V1 hashes the UTF-8, no-BOM bytes of a canonical JSON-lines inventory. Each file produces exactly one JSON object followed by a mandatory LF byte, including the final record. Keys appear in this exact order: `path,type,role,mode,size,sha256`. Paths are already-normalized ASCII relative paths and are sorted using bytewise `strcmp` order; the serializer never rewrites them. `role` is the closed V1 package-entry role, `mode` is a four-character lowercase octal string such as `0644`, `size` is a decimal JSON integer, and `sha256` is lowercase 64-character hexadecimal. JSON uses standard escaping with unescaped forward slashes. Empty inventories, unordered or aliasing paths, uppercase digests, and undeclared entry fields fail closed. A checked-in golden JSON-lines fixture and digest are exercised on Linux/PHP 8.2 and Windows/PHP 7.4. Helper's earlier five-key vector (`e0bfea661be042a603f8e396580022e4c0923998bc53f32c6c4e4769c9cead30`) and role-bearing decimal-mode vector (`0360cff4f62a664df3c2a552e0d200cdf684586bf65a3895fe930872bed8dd42`) remain as independent Python/PHP audit references. The authoritative V1 role-bearing, four-digit-octal vector is separately pinned (`a3451d1a2752e46c566116e83ad2caee65d6ccc9f7d828ce73f6a2fe9ac09723`). Package format acceptance is exactly `1.0`; patch/minor variants fail until compatibility semantics are explicitly versioned. Wire JSON preserves object-versus-list identity before associative decoding, and portable paths reject Windows-invalid `<`, `>`, `\"`, `|`, `?`, and `*` characters.


### Deterministic build rules

Trusted CI builds the ZIP from an exact protected tag with:

- an explicit production allowlist rather than broad repository exclusion patterns;
- lexicographically sorted entry paths;
- normalized path separators, timestamps, and permissions;
- no symlinks, device entries, absolute paths, or parent-directory components;
- no `.git`, CI configuration, tests, internal evidence, development-only scripts, local configuration, runtime caches, or secrets;
- the tag or source-commit timestamp used as `SOURCE_DATE_EPOCH`, while actual workflow time remains in detached provenance;
- a second independent build whose content-tree and final ZIP hashes must match.

### Initial production allowlist

The exact list must be implemented and reviewed as code. At a category level, the release package contains:

- public entry points and API routes;
- production PHP source;
- production browser assets and required vendored UI assets;
- package reader, installer, version, and configuration-loader code;
- the current schema baseline and only supported post-baseline migrations;
- required plugin/skill deliverables that are part of the released product;
- LICENSE, third-party notices, release identity, and concise installation documentation.

Dockerfiles, Compose files, host-preflight tooling, full project documentation, test fixtures, and repository-only utilities remain outside the canonical application ZIP unless a concrete runtime dependency proves otherwise.

## Baseline-schema installation model

### First baseline

The first package-era schema baseline targets the already-proven subsequent-release MySQL 8.4 path. RC1 and its MySQL 5.7.44 evidence remain immutable historical provenance and are not retrofitted into this model.

An example baseline identifier is:

`syndicatum-mysql84-1.0.0-baseline.1`

The final name should be machine-readable, immutable after release, and unique to the exact normalized schema.

### Baseline generation and drift prevention

The repository stores a generated baseline such as `schema/baselines/1.0.0.sql` and its metadata. CI proves it with two independent databases:

1. Start a pinned, supported MySQL 8.4 database.
2. Construct the accepted current schema using the historical source path in CI only.
3. Produce a normalized schema-only dump with non-semantic noise removed.
4. Compare it byte-for-byte with the committed baseline.
5. Start a second empty MySQL 8.4 database.
6. Apply only the committed baseline to that database.
7. Compare normalized `information_schema` and object definitions between the two databases.
8. Run the full application contract and lifecycle suites against the baseline-created database.

Any difference fails the build. A developer cannot change migrations or application schema assumptions without regenerating and reviewing the baseline evidence.

### Installation state

The database records one authoritative installation row containing:

- application version;
- schema baseline identifier;
- current schema head/version;
- source package SHA-256;
- package format version;
- installation identifier and timestamp;
- last successful upgrade identifier and timestamp, when applicable.

A protected local installed marker allows the front controller to distinguish installer mode before opening the database. The marker contains identifiers and hashes, not reusable credentials.

### Fresh-install sequence

1. Acquire an installation lock.
2. Validate the package and baseline before connecting to the target database.
3. Require a supported, empty target database.
4. Apply the current baseline directly.
5. Insert the installation-state record.
6. Create the initial administrator using the existing bootstrap lock and password protections.
7. Write protected configuration atomically.
8. Run schema, login, and application-health verification.
9. Write the installed marker last and consume the installer authorization.

MySQL DDL is not transactionally reversible. Therefore, if installation fails after schema changes begin, the installer must report the exact incomplete stage and require cleanup of the target empty database or a verified idempotent resume. It must never advertise the instance as installed merely because some tables exist.

### Post-baseline migrations

Fresh installations do not create fictitious records for every historical migration. The installation-state row declares the baseline cutover. The migrator:

- ignores migrations at or before the installed baseline cutover;
- evaluates only migrations declared after that baseline;
- validates checksums for every applied post-baseline migration;
- rejects an unknown baseline, unsupported release jump, checksum change, or downgrade;
- serializes schema changes using the existing database migration lock.

Historical migrations remain in the repository for evidence and supported legacy upgrade tooling but need not be distributed in a new clean package unless the release explicitly supports upgrading from that historical state.

## Web installer flow

### Database-independent bootstrap

The installer has a separate front controller and minimal assets that do not include the normal DB-first API bootstrap. The main front controller checks only the protected installed marker:

- installed marker present and valid: load the application;
- installed marker absent: route to the installer;
- contradictory marker/config state: fail closed with operator recovery instructions.

### First-run authorization

The recommended minimum mechanism is an out-of-band bootstrap token:

1. The filesystem/environment operator creates a high-entropy token using the hosting control panel, CLI, or secret manager.
2. The token hash is stored in a protected file outside the public document root or supplied as an environment secret.
3. The operator enters the token in the installer over HTTPS.
4. The installer uses constant-time comparison, rate limiting, short expiry, and a Secure, HttpOnly, SameSite=Strict installer session.
5. Successful installation consumes and removes the bootstrap token.

The canonical public ZIP never contains an instance-specific bootstrap secret. The installer never displays a newly generated ownership secret to an unauthenticated visitor.

For hosting without shell access, the UI may display the required protected-file location and instruct the filesystem owner to create the token file through the hosting file manager. Filesystem control, not URL arrival order, proves ownership.

### System check

The installer verifies before accepting credentials:

- supported PHP version and 64-bit runtime;
- required extensions, including PDO MySQL, JSON, OpenSSL, fileinfo, GD, ZIP, and Sodium for recovery packages;
- HTTPS, with external activation blocked if transport security is absent;
- secure cookies and session support;
- writable protected configuration, temporary, and persistent-asset locations;
- private paths are outside and not HTTP-reachable from the public document root;
- sufficient upload, execution-time, memory, and disk limits for the selected action;
- canonical package manifest and checksums are valid;
- installer authorization is valid.

Apache `.htaccess` is defense in depth only. It is not accepted as the sole protection for secrets on unknown hosting stacks.

### Database step

The installer accepts:

- host and port;
- database/schema name;
- application username and password;
- TLS mode;
- CA certificate or platform trust selection where required.

It supports local, remote, dedicated, and managed MySQL. It does not require MySQL on the web host.

Before installation, it verifies:

- server identity and TLS behavior;
- supported MySQL 8.4 release;
- an empty target schema;
- required character set and collation support;
- required SQL mode;
- connection time-zone behavior;
- privileges needed to create and upgrade the schema;
- absence of unsafe broad privileges where the installer can determine them.

The database itself is expected to exist. Minimum V1 does not require permission to create or administer the MySQL server.

### Administrator and completion

The installer collects administrator display name, email, and a password meeting the existing minimum policy. It uses the existing race-safe administrator bootstrap logic after the baseline exists.

On completion it:

- atomically writes protected configuration;
- records package and schema identity;
- consumes installer authorization;
- disables installer mutation routes;
- verifies administrator login and essential application health;
- redirects to the normal application.

## Configuration and secret model

### Provider precedence

The application gains one explicit configuration loader with this precedence:

1. deployment environment or mounted secret values;
2. explicitly configured external secrets/config file;
3. installer-written protected configuration file.

Conflicting providers fail with a diagnostic rather than silently selecting mixed credentials. Docker continues to use environment variables or mounted secrets. Traditional hosting uses an installer-written file outside the public document root with restrictive permissions and atomic replacement.

### Secrets that define instance identity

The current application depends on cryptographic material that is not fully represented by the database:

- `PBB_AGENTCHAT_SECRET` protects token/claim semantics and several encrypted integration values;
- `PBB_AGENTCHAT_PREVIOUS_SECRET`, when active, is part of an incomplete rotation;
- `SYNDICATUM_MASTER_KEY` protects stored integration settings.

A full recovery that must preserve active integration/token decryptability needs these values. They may appear only inside the authenticated-encrypted backup payload.

Infrastructure credentials are target-specific and are not exported:

- target MySQL username/password;
- MySQL root password;
- hosting account, SSH, object-store, or reverse-proxy credentials;
- environment-only external-provider overrides that the instance cannot safely read or export.

The restore flow asks the operator to supply target infrastructure credentials separately.

## Unified Backup / Restore surface

The administrator menu order becomes:

1. Users
2. Audit
3. Delivery health, where retained by the current product navigation
4. Backup / Restore
5. Settings

The new surface is visible only to system administrators and presents three product actions.

### Get clean Syndicatum package

The application reads its installed release identity and offers a verified link or proxy download for the corresponding canonical release, subject to release policy. It displays:

- release version and source identity;
- schema baseline;
- package SHA-256;
- compatibility summary;
- release notes/provenance reference.

The running instance never rebuilds this package from local application files.

### Build backup package

Minimum V1 asks for:

- recovery password, required;
- optional operator note;
- explicit confirmation that the password is not recoverable by Syndicatum.

Registered persistent assets are always included for a complete recovery. The current registry contains the avatar directory. An option to omit required persistent assets is not offered in minimum V1 because it would create a knowingly incomplete recovery package.

The backup producer:

1. re-authenticates the administrator;
2. validates CSRF and capability;
3. acquires a backup coordination lock;
4. starts a consistent database snapshot;
5. streams data through the backup serializer rather than holding the archive in PHP memory;
6. captures registered persistent assets from the same logical checkpoint where possible;
7. creates recovery metadata and a strict inventory;
8. encrypts the payload with authenticated streaming encryption;
9. writes only to protected temporary storage;
10. exposes a short-lived, single-use download;
11. records a non-secret audit event and cleans up temporary files.

### Backup data contract

The release baseline supplies DDL. A backup therefore contains logical application data, not arbitrary schema SQL or executable database routines.

The portable data format must preserve MySQL types without lossy string conversion. Binary values are encoded explicitly. Tables and columns come from a baseline-specific allowlist. Restore order and identity-column behavior are part of the baseline metadata.

All durable business and audit data required for recovery is retained. On restore, security-sensitive transient state is reset rather than resumed blindly:

- web sessions and CSRF material are invalidated;
- OAuth attempts and installer/bootstrap tokens are discarded;
- rate-limit windows and worker heartbeat state are regenerated;
- one-time claim codes and other expired/consumable challenges are invalidated;
- pending external deliveries are restored into a paused reconciliation state so restore cannot duplicate notifications before operator review;
- durable delivery/audit history remains available.

The final table classification must be generated from the baseline and reviewed as an explicit artifact. Unknown tables fail the backup rather than being silently skipped.

### Trusted baseline recovery policy

The trusted release baseline—not the backup—defines the target schema and table policies. Backup metadata may identify its released baseline/schema head to select a supported path, but it cannot introduce DDL or reclassify tables.

V1 freezes three table policies:

- `durable`: data is portable and restored; metadata requires a unique restore order, non-empty identity columns, an explicit `preserve` or `recompute` sequence-state rule, and closed-enum integrity checks.
- `reset`: schema exists but live contents are rebuilt using one declared strategy: `truncate`, `recreate_default_row`, `regenerate_on_start`, or `rebuild_from_durable_state`.
- `excluded`: data is outside portable recovery; metadata requires a reason (`environment_local`, `security_local`, `non_portable`, or `deprecated`) and a target expectation (`absent`, `empty`, `locally_initialized`, or `operator_supplied`).

The policy map must exactly match the trusted baseline schema inventory. Duplicate tables, restore-order collisions, unknown integrity/reset/exclusion values, migration ID or digest reuse, and parent-after-child foreign-key restore order fail closed. Baseline metadata is versioned and published release identity is immutable.


### Backup encryption envelope

The recommended V1 implementation requires PHP Sodium and uses:

- Argon2id password-based key derivation with versioned, recorded parameters and a random salt;
- `secretstream_xchacha20poly1305` or an equivalently reviewed authenticated streaming construction;
- a clear minimal envelope header containing only format/kind, KDF parameters, salt, nonce/header material, and encrypted-payload length/hash;
- the full backup manifest, data, asset names, notes, and portable secrets inside the encrypted payload.

The recovery password is never stored. Wrong-password and tampered packages fail before any target write. Encryption parameters are versioned so they can be strengthened without ambiguity.

## Restore behavior

### Supported target

Minimum V1 restores only to:

- a new empty Syndicatum instance with a supported canonical release; or
- a separately staged empty database and protected asset directory controlled by an administrator.

The target must not be the database currently serving the restore UI. If the product cannot prove separation, it refuses the operation.

### Restore sequence

1. Re-authenticate the administrator and validate CSRF/capability.
2. Upload or select the backup into protected quarantine storage.
3. Enforce upload, archive, file-count, expanded-size, and processing-time limits.
4. Reject absolute paths, traversal, symlinks, devices, duplicate normalized names, executable entries, ambiguous Unicode paths, and compression bombs.
5. Validate envelope structure, authenticated decryption, inner manifest, inventory, and content hashes before a target write.
6. Show source release, schema baseline, creation time, data classes, registered assets, compatibility, and intended target action.
7. Require explicit confirmation and target credentials.
8. Verify the target database and asset directory are empty/staged.
9. Apply the matching canonical baseline.
10. Import logical data and registered assets.
11. Install portable instance keys atomically in protected target configuration.
12. Invalidate transient credentials/state and pause pending external deliveries.
13. Apply only supported forward migrations from the package baseline to the target release.
14. Run schema, row-count, relationship, asset, authentication, integration-decryption, queue, and application-health checks.
15. Produce a checksummed restore report and explicit cutover checklist.
16. Audit completion without recording the recovery password or secret values.

Failed restore leaves the current live instance untouched. The staged target is marked incomplete and cannot be activated until verification succeeds.

### Cutover

V1 cutover is explicit and operator-controlled. Depending on hosting, it may mean changing the protected DB configuration, switching a document-root symlink, updating a reverse proxy, or promoting a staged service. The product provides exact verified source and target identities but does not automate arbitrary hosting control planes.

## Upgrade model

Fresh install and upgrade are separate operations:

- **Fresh install:** empty DB → current release baseline → initial administrator → health verification.
- **Upgrade:** known installed release/baseline → mandatory verified backup → exact declared forward migrations → application/package switch → health verification.

Each clean release declares:

- its schema baseline and head;
- the earliest directly supported source release;
- the exact forward migration set for each supported source;
- required PHP/MySQL/package-reader compatibility;
- whether a staged upgrade is mandatory.

Unknown versions, pre-release development schemas, downgrades, gaps in the migration graph, and modified migration checksums fail closed.

The first package-era release may provide a separately tested transition from the current internal deployment and MySQL 5.7 evidence path to MySQL 8.4. That transition is migration tooling, not the fresh-install path and not a rewrite of RC1 provenance.

## Docker and GitHub integration

### Docker as a downstream adapter

After the package/install contract is stable, a Docker build receives the exact canonical ZIP and detached checksum as build inputs. It:

- verifies the checksum before extraction;
- extracts only with the shared package reader or equivalent strict validation;
- records application version, source commit, schema baseline, and canonical package SHA-256 in OCI labels;
- contains no independent application-copy or schema-construction logic;
- uses environment/mounted secrets and a headless installer adapter;
- invokes the same baseline installer and forward migrator as the web flow.

The Docker build should not fetch an unpinned “latest” artifact. CI should pass the verified ZIP through the build context or an equivalently immutable, digest-addressed source.

### GitHub release

GitHub Releases publishes the canonical ZIP, detached checksum, provenance, notices, and release notes. CI then downloads the published ZIP and runs clean installation from that exact public artifact on MySQL 8.4.

Future container images must prove which canonical package hash they contain. A container image without that label/provenance relationship is not a release-equivalent adapter.

## Existing work retained and reused

The redesign preserves rather than discards the completed work:

- protected-main PR and exact-head CI controls;
- source-contract test suites;
- current schema migrations and checksum enforcement for historical evidence and post-baseline evolution;
- `AuthService::bootstrapAdministrator()` locking and initial-role behavior;
- `AvatarService` validation and the persistent avatar storage boundary;
- administrator authorization, CSRF, re-authentication patterns, and audit infrastructure;
- deterministic source archive/checksum/provenance concepts from release CI;
- archived-candidate Docker lifecycle testing;
- MySQL 5.7 → 8.4 migration acceptance evidence;
- MySQL 8.4 clean-install and image-security work;
- logical backup/restore probes in Docker acceptance;
- external-host preflight tooling as a future Docker/host adapter gate;
- security inventory and hardening evidence.

The external-host Docker acceptance gate is paused, not waived or closed. It resumes after Docker consumes the canonical package and the adapter equivalence tests are ready.

## Impact on the commercial-viability checklist

This proposal replans the implementation path for Phase 0 deployment and release trust. It does not declare those gates complete.

The checklist should gain explicit evidence items for:

- deterministic canonical package construction and publication;
- production allowlist and absence of secrets/development files;
- committed MySQL 8.4 baseline drift verification;
- clean baseline installation with zero historical migration execution;
- ownership-safe database-free web installation;
- local, remote, and TLS-managed MySQL installation paths;
- protected configuration across traditional hosting and Docker;
- encrypted backup creation and tamper/wrong-password rejection;
- non-executable backup payload enforcement;
- staged restore, transient-state invalidation, delivery reconciliation, and health verification;
- canonical-package equivalence across direct PHP and Docker adapters;
- published ZIP download and installation acceptance.

RC1/MySQL 5.7 items remain historical and unchanged. The new first baseline and future release gate use the proven MySQL 8.4 path.

## Implementation phases

Jonathan approved the refactor in Syndicatum message #2957 and explicitly changed the execution order to UI-first. Missing backend capabilities must appear only as clearly labeled, fail-closed placeholders. Native Helper components and reusable helpers/services are required before custom controls or controller-owned low-level logic.

### Phase 1 — UI-first foundation and contracts

Deliver:

- first-run installer shell and approved step navigation using native Helper components;
- administrator **Backup / Restore** navigation item and landing/status surface;
- clean-package, build-backup, and staged-restore workflow screens;
- Settings installation-identity area and Audit presentation hooks;
- responsive, keyboard, focus, loading, empty, offline, and error states;
- explicit **Not yet available** placeholders and disabled actions for missing backend operations;
- stub API contracts that fail closed and cannot represent a placeholder as a completed operation;
- package format/version specification;
- release and backup payload allowlists;
- table/state classification for backup and restore;
- installation-state schema;
- threat model for installer, package ingestion, backup, and restore;
- UI/API/state/accessibility specifications and acceptance-test skeletons;
- fixture packages covering valid, tampered, traversal, duplicate-path, zip-bomb, wrong-kind, and wrong-password cases.

Exit criteria:

- the visible UI is composed Helper-first and clearly distinguishes placeholders from real state;
- no placeholder can claim a real install, backup, restore, checksum verification, or download;
- non-administrators cannot discover administrator-only surface metadata;
- parser/validator tests are fail-closed;
- the package contract is reviewed before producer code exists;
- all required metadata and compatibility rules are unambiguous.

### Phase 2 — Helper/service and package-baseline foundation

Deliver:

- reusable services for manifest parsing/validation, installation identity, baseline metadata, bootstrap ownership proof, compatibility, encryption/decryption, archive/path safety, persistent-asset classification, audit emission, and canonical provenance lookup;
- deterministic baseline generator and drift check;
- committed MySQL 8.4 baseline;
- baseline-aware migration model;
- deterministic production ZIP builder;
- manifest, detached checksum, and provenance;
- CI reproducibility and clean-install jobs.

Exit criteria:

- two builds of one exact tag are byte-identical;
- the committed baseline equals the schema produced by the accepted historical path;
- a fresh database reaches a healthy application using only the baseline;
- CI proves no pre-baseline migration executes during fresh install;
- the artifact contains only allowlisted production files.

### Phase 3 — Connect the web installer

Deliver:

- DB-independent installer front controller;
- out-of-band bootstrap authorization;
- system and private-path checks;
- local/remote/managed MySQL configuration with TLS;
- atomic protected config provider;
- administrator bootstrap, installed marker, lockout, and health verification;
- headless installer interface for automation.

Exit criteria:

- an unauthenticated first visitor cannot claim ownership;
- installation succeeds from the published ZIP on a clean traditional PHP host;
- local and remote MySQL 8.4 paths pass;
- missing HTTPS/private-path/extension/privilege requirements fail clearly and safely;
- retries after simulated failures do not create a falsely installed or double-admin state.

### Phase 4 — Connect encrypted backup and staged restore

Deliver:

- Backup / Restore administrator surface before Settings;
- canonical-release retrieval action;
- streaming logical backup producer;
- authenticated encryption envelope;
- registered persistent-asset collection;
- quarantine validator and strict reader;
- empty/staged target restore;
- transient-state invalidation and delivery reconciliation;
- health, integrity, and cutover report.

Exit criteria:

- non-administrators and non-re-authenticated sessions cannot build, download, validate, or restore;
- wrong passwords and single-byte tampering fail before any write;
- backup contains no executable application entry;
- restored messages, membership, audit evidence, settings, integrations, and avatars match the source contract;
- sessions/one-time challenges are invalidated and pending deliveries cannot duplicate silently;
- the live source remains unchanged by failed or successful staged restore;
- restore evidence is checksummed and audited without secret leakage.

### Phase 5 — Release, GitHub, and Docker adapters

Deliver:

- canonical ZIP publication in GitHub Releases;
- published-artifact installation acceptance;
- Docker build from the exact ZIP;
- OCI labels linking image to package digest;
- headless baseline installation in Docker;
- adapter-equivalence tests;
- updated external-host runbook and preflight linkage.

Exit criteria:

- direct PHP and Docker installations report the same application/package/schema identity;
- both paths pass the same coordination contract and lifecycle tests;
- Docker contains no divergent application-copy or fresh-schema path;
- external-host acceptance runs the canonical-package-based adapter.

### Phase 6 — Checklist and release-gate review

Deliver:

- synchronized release policy, deployment docs, migration notes, security table, and commercial-viability checklist;
- Commercial Assessor review of each evidence-backed gate change;
- owner-visible summary of remaining external/design-partner gates.

## Acceptance criteria for the complete proposal

The redesign is ready for external release consideration only when all of the following are proven:

- The canonical ZIP is CI-built from an exact protected tag and reproducible.
- The detached ZIP checksum and internal content-tree hashes verify.
- The ZIP contains no secret, local config, development-only file, or unapproved executable.
- The committed MySQL 8.4 baseline matches the accepted current schema.
- Fresh installation executes the baseline and zero pre-baseline migrations.
- The installer runs before DB configuration and cannot be claimed by an arbitrary first visitor.
- Traditional-host configuration is outside the public tree and Docker retains secret injection.
- Local, remote, and TLS-managed MySQL installation behaviors are verified.
- Installation records exact package, application, baseline, and schema identity.
- Upgrade applies only the declared post-baseline migration path.
- Backup creation is administrator-only, re-authenticated, audited, streaming, encrypted, and non-executable.
- Restore validates before writing and targets an empty/staged destination.
- Restored durable data and assets match; transient credentials are invalidated; deliveries are reconciled safely.
- GitHub publishes and re-downloads the exact canonical artifact.
- Docker verifies, contains, and reports the exact canonical package hash.
- Existing coordination, security, migration, and lifecycle suites remain green.
- The Commercial Assessor accepts every claimed gate closure from retained evidence.

## Security model

The primary threats and controls are:

| Threat | Required control |
|---|---|
| Internet visitor claims an uninstalled instance | Out-of-band, expiring, single-use operator proof |
| Installer writes secrets into a public path | External protected config path plus reachability check and fail-closed behavior |
| Malicious release package | CI-only producer, strict allowlist, internal hashes, detached checksum, retained provenance |
| Malicious backup gains code execution | Backup kind forbids all executable entries; data-only importer |
| Zip Slip, symlink, device, duplicate-path, or bomb | Canonical path validation, no links/devices, quotas, ratio/count limits, quarantine |
| Backup disclosure | Mandatory authenticated encryption and protected short-lived storage/download |
| Password guessing | Argon2id parameters, rate limiting, no online long-term archive oracle |
| Partial install treated as complete | Installed marker written last; resumable/cleanup state and health gate |
| Restore corrupts live service | Empty/staged target only; no self-overwrite |
| Restore duplicates external notifications | Pending delivery pause/reconciliation before activation |
| Restored browser/OAuth credentials remain active | Mandatory invalidation of transient sessions and one-time challenges |
| Schema drift | Generated committed baseline plus two-database CI comparison |
| Upgrade skips unsupported history | Explicit release/baseline migration graph; unknown paths rejected |
| Container diverges from direct package | Exact package digest in build input, OCI labels, and adapter-equivalence tests |

## Risks and open implementation questions

These do not block the architectural decision but must be resolved during Phase 1:

1. **Shared-host limits:** large databases may exceed request time or disk quotas. V1 needs documented supported limits, streaming, progress/cancellation behavior, and protected cleanup. A background/CLI path may be required above that limit.
2. **Consistent asset snapshot:** database snapshot and avatar collection cannot be perfectly atomic without write coordination. Define a short backup lock or asset journal so manifest references remain consistent.
3. **Table classification:** durable deliveries, pending outboxes, claims, OAuth attempts, and operational telemetry need an explicit restore policy to avoid either data loss or duplicate external effects.
4. **Secret portability:** confirm which integration credentials are DB ciphertext and which may exist only as environment overrides. The package must not promise recovery of secrets it cannot read.
5. **Hosting private path:** some shared hosts cannot write outside the document root. Such hosts must provide a verifiably non-public private directory or be declared unsupported; `.htaccess` alone is insufficient.
6. **MySQL privileges:** the exact minimum install/upgrade/runtime privilege sets should be separated where hosting permits, while acknowledging that many shared hosts provide one schema owner.
7. **TLS variability:** managed MySQL CA handling and hostname verification need fixtures across supported providers without adding provider-specific logic to the core.
8. **Reproducible ZIP tooling:** select and pin a builder whose metadata normalization is identical on CI runners.
9. **Existing internal deployment transition:** define whether it migrates through the existing 5.7 → 8.4 process before entering the package-era baseline or through a dedicated one-time export/import path.
10. **Release retrieval in the admin UI:** define the trusted release index and behavior when the server has no outbound network access. Manual verified upload must remain possible.

## Explicitly deferred from minimum V1

- destructive live in-place restore;
- automatic hosting-control-plane or DNS cutover and rollback;
- downgrade migrations;
- incremental, differential, deduplicated, or continuous backups;
- backup scheduling, retention orchestration, and object-store lifecycle management;
- unencrypted recovery archives;
- redacted analytics/data-export package kinds;
- package-carried executable plugins or extensions;
- multi-node, high-availability, or point-in-time database recovery;
- broad database portability beyond the supported MySQL path;
- a general-purpose deployment orchestration system;
- mandatory public-key package signing infrastructure, while retaining detached hashes and protected provenance;
- arbitrary old-development-schema upgrade paths;
- rewriting RC1/MySQL 5.7 artifacts or provenance.

## Migration and release implications

- The external-host Docker gate remains paused until Docker consumes the canonical package.
- The first package-era release is a subsequent release based on the proven MySQL 8.4 path.
- RC1 remains an immutable internal MySQL 5.7 candidate and continues to provide historical evidence only.
- Existing migrations remain in source control; the package baseline becomes the fresh-install authority.
- Release automation changes from a broad source tarball to a production allowlisted ZIP.
- Release notes must declare the package format, baseline, supported installation paths, supported upgrade sources, and restore limitations.
- A package-era release is not ready merely because CI builds a ZIP. It must pass published-artifact installation, encrypted backup, staged restore, adapter-equivalence, and Assessor review.

## Owner decision recorded

Jonathan approved the refactor in Syndicatum message #2957 and superseded the original contracts-before-UI sequence. Implementation is UI-first with explicit, non-deceptive placeholders, then Helper-first services beneath those surfaces, followed by package/baseline, encrypted backup/staged restore, and finally Docker adapter integration. The external-host Docker gate remains paused until adapter equivalence is proven. Existing CI, security, migration, and RC1 evidence remains immutable, and no placeholder may claim that a real install, package retrieval, checksum verification, backup, or restore has completed.
