# V1 release and compatibility policy — candidate

**Status:** Proposed policy for the first whole-application V1 release. No V1
application tag or published release is authorized or implied by this document.
The [implementation checklist](v1-commercial-viability-implementation-checklist.md)
remains the release gate.

## Version identities

- Version the whole Syndicatum application with `vMAJOR.MINOR.PATCH` Git tags.
  Use `-rc.N` for pre-release candidates; an RC is not production approval.
- Publish exactly one source archive and SHA-256 manifest for the tagged commit.
  Record the full Git commit, tag, archive hash, migration head, declared
  database/runtime baseline, V1 coordination-contract revision, and producing
  and verifying CI workflow/run identities in release notes. A tag must not be
  moved after publication; corrections require a new candidate or patch version.
- Codex plugin and browser Companion versions have separate package identities.
  Release notes must list the exact package versions tested during installed-
  client acceptance. State a wider compatibility range only when separate
  contract/tests support it; an application tag does not silently version or
  certify those clients.
- The project API path `/api/v1/` is a protocol major version, not an
  application build number. The candidate coordination contract has its own
  revision until its observable semantics are reviewed and frozen.

## Change classification

- **Patch:** fixes behavior without changing documented V1 meaning, required
  inputs, authorization, or stored-message interpretation. A security fix may
  need a patch even when an operational workaround is documented.
- **Minor:** adds backward-compatible optional fields, endpoints, capabilities,
  or UI features. Existing V1 clients must continue to work without adopting
  the addition. Optional transports require explicit capability detection.
- **Breaking public-contract change:** removes or renames public fields, adds
  required inputs, changes ID/cursor, acknowledgement, or canonical-message
  meaning, or materially changes existing authorization semantics. Existing
  principals must not gain access by default without an explicit security and
  compatibility review. A new optional capability can be minor if old scopes
  keep exactly their prior meaning. Do not silently redefine `/api/v1/`;
  introduce an appropriate versioned boundary and migration window.
- **Database migration:** may ship in a patch or minor release when externally
  observable V1 semantics remain compatible. Released migration checksums are
  immutable. Irreversible schema boundaries must be documented and tested with
  file-plus-database recovery; internal migration numbering does not determine
  the public application/API major version.

## Promotion and recovery

1. Cut an RC from a reviewed commit on protected `main`; the two required V1
   checks must pass for that exact revision. Publish a checksummed artifact
   from the tag, then independently verify the downloaded hash.
2. Install that artifact on a clean supported environment. Verify runtime
   identity, exact MySQL 5.7.44 strict-mode baseline, migrations, health,
   backup/restore, and the supported client/coordination acceptance matrix.
3. First `v1.0.0` supports a fresh Docker installation on the declared runtime
   and database baseline only. The current internal Syndicatum deployment is
   not a prior supported commercial release; an in-place upgrade from it is
   outside the `v1.0.0` support promise and is not a release gate. Record its
   revision, schema state, and configuration separately if offering data-
   migration assistance. For releases after `v1.0.0`, rehearse upgrade from the
   immediately previous supported application release and preserve an off-host
   database backup and matching file/configuration snapshot for that path.
4. For subsequent supported upgrades, rehearse failed-upgrade recovery. Restore
   files and database together when schema changes make application-only
   rollback unsafe; do not edit applied migration checksums or promise reverse
   migrations that do not exist.
5. Publish stable `v1.0.0` only after release, security, legal, and installed-
   client gates close. Keep the release notes, checksums, migration notes, and
   known limitations with the tag. A failed RC is replaced by a new RC tag,
   never overwritten. Never silently promote an RC by moving or reusing its
   tag; stable identity must be published under its own tag and verified
   artifact provenance.

The existing [Docker runbook](docker-deployment.md) supplies operator commands.
The [2026-09-17 acceptance record](docker-acceptance-2026-09-17.md) proves a
checksummed CI candidate archive on the chosen database baseline, but not a
tagged published release, upgrade, rollback, or production readiness.
