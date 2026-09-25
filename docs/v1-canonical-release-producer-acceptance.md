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
baseline metadata keeps cutover `202609180004` and advances schema head through
an explicit checksummed post-baseline inventory. Post-baseline identifiers must
be unique 12-digit values.
The normalized cutover `202609180004` maps exactly to the historical migration
identity `202609180004_delivery_terminal_timestamps`; the numeric baseline ID is
not a second migration and must never be inserted as fabricated migration
history.

## Authoritative baseline generation

The committed baseline SQL is generated from the trusted current schema state on
the pinned MySQL 8.4 reference environment; it is not independently hand-edited.
Generation normalizes environment-dependent output deterministically and CI
compares regenerated bytes and SHA-256 with the committed artifact. Drift fails
the build.

Baseline acceptance proves that:

- applying the SQL to an empty MySQL 8.4 database yields the exact expected
  schema under the declared charset, collation, and SQL modes;
- every resulting live table has an exact trusted `BaselineMetadata`
  classification;
- installation records the baseline ID and current schema head without
  synthesizing historical migration rows, while recording only post-baseline
  migrations it actually applies;
- the legacy migration engine, when invoked after baseline installation, skips
  all migrations at or before the cutover and considers only declared,
  checksummed post-baseline migrations;
- application version, baseline ID, schema head, baseline freeze commit, release
  package source commit, and release package SHA-256 remain separate identities.

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
not pass from the packaged runtime. The required path is:

`empty database -> authoritative baseline -> installation/schema identity -> initial owner/admin -> health verification`.

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

## Gate authority and implementation evidence

Under the role clarification recorded in Syndicatum message 3035, Commercial
Assessor owns the backend/package/security gate decision. Helper is not a
standing backend approver and is involved only for a specifically requested
technical review or a visible UI/UX contract.

The implementation candidate is recorded at branch head
`34d9f7781e447d2d7d3d4bb24f2a35ef26a9732b`. PR run
[35496204277](https://github.com/jybanez/syndicatum/actions/runs/35496204277)
passed all nine jobs, including the new `canonical-release-candidate` job. The
PR job used merge candidate `ef55500b3a6e47ebc8562d9b20e3d80f70b9b6ef`
and produced the same ZIP twice with these exact facts:

- ZIP SHA-256: `c88ea6865d8526f6674945a6d1b99908af92f721effb940f806190ffbaf97565`;
- manifest SHA-256: `5c6a636d24b92a6b76a1cdb170a1944fffbb5f893ef61d871cd10738391a7f11`;
- content-tree SHA-256: `12b14af23f5a55fd6c63e86cca8f190ca71bdd0bd101f1d43a06ec89402e4d46`;
- 256 exact package files from 285 explicit ship/exclude dispositions;
- nine producer failure/normalization tests passed;
- the frozen reader and controlled POSIX extractor accepted the exact ZIP and
  reproduced the manifest inventory.

The same deterministic ZIP bytes were locally passed through the frozen reader,
materialized from verified package namespaces, and installed into empty pinned
MySQL 8.4.11. Acceptance recorded 48 tables, the exact package/release/baseline
identity, zero historical migration rows, healthy application/worker/MCP paths,
and successful backup/restore.

The annotated-tag production path is wired as the sole canonical emitter, and
publication consumes rather than rebuilds its artifact. It has not yet executed
from a new protected-main tag, so this record is **IMPLEMENTATION CANDIDATE
COMPLETE / PRODUCTION TAG EVIDENCE OPEN** pending Commercial Assessor review,
merge, and the authorized tag run.
