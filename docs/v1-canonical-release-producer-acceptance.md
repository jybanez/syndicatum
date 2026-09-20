# V1 canonical release producer acceptance contract

Status: contract accepted by Commercial Assessor in Syndicatum #3009;
implementation and exact-artifact acceptance remain in progress. This gate
covers only the trusted CI release producer. It does not authorize backup
production, restore, installation, promotion, or real UI actions.

## Authority boundary

The canonical release producer is CI tooling, not application functionality. It
must have no web route or runtime service entry point. A running Syndicatum
instance cannot invoke it or mint an executable release.

Canonical status is publication authority, not secrecy or an assertion that the
deterministic algorithm cannot be run elsewhere. Reproduced bytes are not an
authoritative canonical release unless protected CI generated and published
matching checksum and provenance for the protected source identity.

One declared Linux CI environment produces the canonical bytes. Other jobs may
consume and verify the artifact but must not publish a competing canonical ZIP
unless exact byte identity is proved.

## Trusted source preflight

Production requires all of the following before any output is created:

- trusted GitHub Actions release automation on a tag-triggered push;
- a full source commit matching both `GITHUB_SHA` and checked-out `HEAD`;
- an annotated release tag resolving exactly to that commit;
- the commit is contained by the fetched protected `main` reference;
- a completely clean tracked and untracked worktree;
- a versioned, exact production file inventory and producer policy committed in
  the same source tree;
- a committed baseline schema and metadata whose source identity, baseline ID,
  schema head, and digests agree with the producer policy.

Failure of any precondition creates no canonical artifact.

Payload bytes are read from immutable Git objects at the accepted commit using
the exact tree/blob identities, not from mutable checkout paths. The checked-out
policy and producer entry points must byte-match their blobs at that commit.
The clean-tree check remains a defense-in-depth release preflight.

## Deterministic archive

The producer consumes an exact, bytewise-sorted inventory. Every source path,
package path, role, and normalized mode is declared. Duplicate, case-folded,
ancestor, or unmapped paths fail closed. A missing declared file, an unexpected
tracked production candidate, a symlink, a non-regular file, or a source file
whose digest changes during production aborts the build.

The versioned policy defines closed source candidate roots and an explicit
ship-or-exclude disposition for every tracked path in those roots. Therefore a
new endpoint, runtime file, plugin, skill, schema file, or notice cannot silently
evade review merely because it was not added to the shipping inventory.

ZIP entry order, timestamps, Unix creator/type bits, modes, compression method,
compression level, flags, comments, and extra fields are normalized. The
producer version and compression implementation are pinned CI inputs. Building
twice from the same exact source and policy must yield identical ZIP bytes and
SHA-256.

The allowlist excludes at least:

- `.git`, GitHub workflow metadata, tests, development fixtures, local evidence,
  caches, temporary files, and editor files;
- environment files, local configuration, credentials, tokens, keys, database
  dumps, avatars, uploaded assets, and backup/recovery payloads;
- historical pre-baseline migration replay from the fresh-install payload.

## Manifest and sidecars

The manifest is a sidecar and uses the frozen `PackageManifest` V1 contract
without producer-only extensions. Its sorted file inventory is calculated from
the bytes actually placed in the ZIP. `content_tree_sha256` must match the
canonical JSONL inventory representation.

The producer emits:

- the canonical release ZIP;
- `manifest.json` with deterministic bytes;
- a detached whole-ZIP SHA-256 file;
- provenance binding the ZIP and manifest hashes to the exact source commit and
  tag, baseline ID, schema head, producer version, workflow identity, run ID,
  and run attempt.

Run-specific provenance remains outside the ZIP so retries do not alter its
canonical bytes. Provenance and checksum mismatches fail publication.

## Release payload policy

Only declared application/runtime, plugin, skill, notice, package-metadata,
baseline, and post-baseline migration entries are eligible. Executable content
is allowed only in the release namespaces and only when explicitly inventoried.
The package declares `contains_data: false` and contains no live instance data,
portable secret, recovery metadata, local configuration, avatar, or credential.
It also declares `contains_persistent_assets: false`; both flags are asserted by
producer tests even though the frozen reader couples the asset flag to roles only
for backup packages.

The schema baseline is authoritative for a fresh installation. Historical
project migrations at or before the baseline cutover are not shipped as the
fresh-install mechanism. Only separately declared, checksummed post-baseline
migrations may appear.

Baseline metadata `source_commit` identifies the commit that froze the baseline
SQL and may predate the release commit. It is not required to self-reference the
release commit that contains or consumes the metadata. The package manifest and
provenance independently bind the exact release source commit. Initial V1
baseline metadata uses cutover=head `202609180004` with no post-baseline
migrations; later post-baseline identifiers must be unique 12-digit values.

## Round-trip acceptance

CI must use the ordinary frozen contracts, with no producer bypass:

1. parse and validate the emitted manifest with `PackageManifest`;
2. validate the exact ZIP using `ArchiveSafetyReader` and trusted release
   context bound to the ZIP hash, manifest hash, source commit/tag, baseline ID,
   and schema head;
3. run controlled extraction into a trusted private POSIX staging chain;
4. compare every extracted file's bytes, size, SHA-256, role, and accepted mode
   with the manifest;
5. recompute and compare the canonical content-tree digest;
6. build the same source twice and compare exact ZIP bytes and SHA-256.

## Fresh-install semantic acceptance

The exact produced artifact must install into an empty supported MySQL 8.4
database using only its committed baseline plus declared post-baseline
migrations. Acceptance fails if any historical pre-baseline migration executes,
if the installed schema drifts from baseline metadata, or if health checks do
not pass from the packaged runtime.

## Failure matrix

Checked-in tests must prove failure for:

- non-CI, non-Linux, non-tag, wrong event, or incomplete workflow identity;
- dirty/untracked source, wrong SHA, lightweight/wrong tag, or commit outside
  protected `main`;
- missing inventory entry, undeclared production candidate, symlink/non-file,
  duplicate/case-fold/ancestor collision, or unsafe package path;
- forbidden secret/data/config path or role;
- baseline metadata/schema/source mismatch;
- manifest/content-tree drift, whole-ZIP checksum drift, or provenance drift;
- nondeterministic second build;
- reader rejection, controlled-extraction rejection, or extracted-tree mismatch;
- fresh install that attempts historical migration replay.

## Independent closure

Helper must independently review this contract and inspect at least one exact
produced ZIP, sidecar set, deterministic rebuild, frozen-reader validation,
controlled extraction, and baseline-only empty-database installation. Commercial
Assessor closes the slice only after exact-head CI and that independent evidence
are recorded. Until then, the release producer remains **IN PROGRESS**.
