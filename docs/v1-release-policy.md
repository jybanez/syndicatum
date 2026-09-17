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
  database/runtime baseline, and V1 coordination-contract revision in release
  notes. A tag must not be moved after publication; corrections require a new
  candidate or patch version.
- Codex plugin and browser Companion versions have separate package identities.
  Release notes must list the exact compatible package versions; an application
  tag does not silently version or certify those installed clients.
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
- **Major or explicitly versioned migration:** removes or renames public fields,
  tightens required inputs, broadens authorization, changes ID/cursor meaning,
  acknowledgement semantics, or canonical message interpretation. Do not ship
  such a change under an existing `/api/v1/` contract without a documented
  migration and compatibility window.
- Database schema changes are not automatically wire-contract changes, but
  migration checksums are immutable once released. A change that prevents
  rollback to the previous supported application version must be called out as
  an upgrade boundary and tested with database backup/restore.

## Promotion and recovery

1. Cut an RC from a reviewed commit on protected `main`; the two required V1
   checks must pass for that exact revision. Publish a checksummed artifact
   from the tag, then independently verify the downloaded hash.
2. Install that artifact on a clean supported environment. Verify runtime
   identity, exact MySQL 5.7.44 strict-mode baseline, migrations, health,
   backup/restore, and the supported client/coordination acceptance matrix.
3. Rehearse upgrade from a named supported previous application state. Preserve
   an off-host database backup and matching file/configuration snapshot.
4. Rehearse a failed-upgrade recovery. Restore files and database together when
   schema changes make application-only rollback unsafe; do not edit applied
   migration checksums or promise reverse migrations that do not exist.
5. Publish stable `v1.0.0` only after release, security, legal, and installed-
   client gates close. Keep the release notes, checksums, migration notes, and
   known limitations with the tag. A failed RC is replaced by a new RC tag,
   never overwritten.

The existing [Docker runbook](docker-deployment.md) supplies operator commands.
The [2026-09-17 acceptance record](docker-acceptance-2026-09-17.md) proves a
checksummed CI candidate archive on the chosen database baseline, but not a
tagged published release, upgrade, rollback, or production readiness.
