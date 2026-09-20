# V1 controlled-extraction contract

Status: accepted at implementation commit
`4ba1cae41b023698016845efd791e3928c41e09d` after independent verification in
Syndicatum #3004 and formal closure in #3005. Package installation, restore,
SQL, and cutover remain deferred. Producer work may proceed only in the ordered,
separately reviewed slices authorized by #3005.

## Explicit operation boundary

`ArchiveSafetyReader::extractToNewStage()` is separate from `validate()`. It does
not accept a caller-forged validation report. In one call it creates a private
immutable archive snapshot, runs the accepted validation contract, and streams
the same validated entry indexes from the same open `ZipArchive` session into a
new private stage. It does not reparse a second archive or rediscover files.

The returned `ArchiveExtractionStage` exposes only the staging path and validation
report. It grants no install, restore, promotion, database, or cutover authority.

## Filesystem trust boundary

Controlled extraction is POSIX-only in V1. Windows validation remains supported,
but extraction fails closed because PHP `chmod()` cannot prove restrictive NTFS
ACLs or safely exclude reparse-point races in an attacker-writable parent.

The trusted staging root must:

- already exist as a real, non-symlink directory;
- be owned by the PHP process effective user;
- have no group or world permission bits;
- be writable by the process;
- be canonically disjoint from the public web root in both directions.

The complete canonical chain from the staging root to the filesystem root is
also checked. Every component must be a real directory owned by root or the
effective process user. Group/world-writable ancestors are rejected unless the
sticky bit supplies the POSIX rename boundary. This admits the conventional
root-owned sticky `/tmp` test parent and the intended root-owned Docker chain,
while rejecting a writable non-sticky parent that lets another UID rename the
private leaf and replace it with a symlink.

The independent reproduction was a preflight/filesystem probe, not an
end-to-end extraction exploit. Against the old `b2cf3f3` code, an explicitly
verified UID 65534 could rename a UID 33-owned `0700` leaf within its writable
non-sticky parent and replace the old pathname with a symlink into the public
tree. The corrected `4ba1cae` preflight rejects that ancestor chain before
snapshot or stage creation. This wording supersedes the original cross-parent
description in Syndicatum #2998, whose first harness did not verify each UID
transition.

Docker provides `/var/lib/syndicatum/staging`, owned by `www-data` with mode
`0700`; the web root remains `/var/www/html`. Archive snapshots for extraction
are created inside this trusted root with mode `0600`.

Each extraction creates a cryptographically random stage using one atomic
`mkdir(..., 0700)`. The resulting canonical path is rechecked inside the trusted
root before any entry is written. Directories are created at `0700`. Files use
exclusive create-new mode and reject pre-existing targets. The already-frozen
path contract rejects traversal, aliases, file/ancestor collisions, invalid
portable segments, and segments longer than 255 bytes before extraction.

## Byte and permission binding

Every file is streamed from the still-open validated ZIP session. Short writes
are completed in a loop. The extractor rechecks the exact accepted byte count
and SHA-256 before flush/fsync/close and chmod. Release files receive their
validated mode. Backup files receive exactly `0600`, regardless of the source
mode. After the second streaming pass, the entire private archive snapshot is
SHA-256 checked again against trusted context before success is returned.

The ZIP handle closes before the private snapshot is unlinked, including on
Windows validation paths. Input and snapshot streams are also closed explicitly
on allocation, copy, flush, and size-limit failures. A clean close and successful
snapshot deletion are success prerequisites, not best-effort cleanup. Deletion
is attempted unconditionally, so an inaccessible parent cannot make a residual
snapshot look absent. If finalization fails after extraction, the new stage is
removed and the operation fails; an undeletable snapshot path is reported for
private operator remediation.

## Failure and cleanup

The extractor records every file and directory it creates. A failure removes
only those recorded paths, deepest first, using `lstat`, `unlink`, and `rmdir`;
it does not recursively discover or follow filesystem entries. If cleanup is
incomplete, extraction still fails and reports the residual private stage path
for operator remediation, retaining the original failure as the previous cause.
No partially extracted stage is returned as success.

## Acceptance evidence

The archive contract suite proves:

- Windows controlled extraction is rejected;
- release extraction preserves accepted bytes;
- backup extraction preserves accepted bytes and forces mode `0600`;
- the stage and created directories remain `0700`, while release files receive
  only their validated safe mode;
- staging/public equality, nesting, and realpath/symlink aliasing are rejected;
- a non-sticky group/world-writable staging ancestor is rejected before any
  snapshot or stage mutation;
- injected inaccessible-parent snapshot deletion failure prevents success and
  preserves the residual path for remediation;
- validation remains side-effect free and cross-platform;
- all reader resource, structure, trust, completeness, and adversarial fixtures
  remain green.

Ubuntu CI exercises successful POSIX extraction. Windows/PHP 7.4 CI exercises
the explicit fail-closed platform boundary. The production Docker image installs
the ZIP extension and creates the private staging root. Exact-head run
`35471353005` passed all seven required jobs at `4ba1cae`; Helper independently
confirmed the unsafe-chain rejection, the trusted
`/var/lib/syndicatum/staging` positive control, and the root-owned sticky `/tmp`
positive control. No application route invokes extraction yet.
