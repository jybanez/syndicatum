# Developer A1: metadata and module/stdin admission — scope review only

Status: NOT EXECUTION AUTHORITY. Applies only to the Developer machine and source
head bound externally by the review. No gate changes are included. One later owner
decision may authorize this entire bounded observation set after source/scope review
and green CI. Old attempts, trees and approvals remain immutable.

## Exact proposed processes

Resolve each executable to its previously observed absolute local path, hash it
again, and STOP on drift before launch. Paths and raw output stay private. Launch
hidden, shell-free, without profile or configuration overrides, from one newly
allocated neutral private capture directory outside webroot, plugin and config.

CLI executable pin: SHA256
1103e9e09c71fa2cd0afd6d8f3b9a2d63174fe8efd7cb5bd09f1cb14ef58a098.
Expected version0.164.0-alpha.11.1. Three separate one-use argv vectors:

1. `--version`
2. `plugin marketplace list --json`
3. `plugin list --json`

These are supported metadata commands at upstream source commit
6905ecede7816e91373e9eabda3f6b0368fc747b (cli/src/marketplace_cmd.rs and
cli/src/plugin_cmd.rs under codex-rs). Version equality is not reproducible binary
provenance. The list commands load ordinary CLI configuration/authentication;
plugin list can refresh remote marketplace metadata. Therefore they are explicitly
process-based A1 operations, not asserted filesystem-pure reads. Do not log secrets,
authentication values or raw configuration. Their native cache/log/network effects
must be accepted in the later owner decision; if the owner requires zero such
writes/network, STOP and do not substitute another command or bypass authentication.
No login, update, marketplace add/remove or plugin add command is allowed.

Each process: at most30 seconds, stdout1MiB, stderr64KiB; no automatic retry.
Nonzero exit, malformed/oversized JSON, timeout, version mismatch, ambiguous source
or another enabled config-layer override STOP the set. Preserve PID/start/image/hash
and bounded output privately. On timeout do not kill or race a possibly active child;
mark subsequent observations unavailable and require independent quiescence. No
successor command runs after failure. Normal stdin EOF/pipe closure is permitted.

## Module/stdin checks, separate from live assembly

Use pinned Node22.12.0, Python3.12.6 and PowerShell7.6.8 executable hashes from the
single admission packet. Source imports must match its17 Git-blob pins first.
Do NOT invoke runCodexHost, CodexToolSession.start, assembly.main/assemble,
PinnedWindowsProcess._start, NativeInstallReader, WindowsBackend, or the storage
worker. Their gates remain unconditional. No fixed-storage open/write is allowed.

Exact one-use argv templates (absolute pinned executable and source paths are resolved privately):

- Python: `-I -B <release>/codex_developer_a1_module_probe.py`
- Node: `<release>/codex_developer_a1_module_probe.mjs`
- PowerShell: `-NoLogo -NoProfile -NonInteractive -File <release>/codex-developer-a1-module-probe.ps1`

This A1-only launch revision uses an explicit nonce launch context; the trusted
runner must obtain fresh canonical owner authority before any capture or command.
It never calls preparation/worker/install gates. The probe payloads import the exact named modules,
accept one48-hex nonce frame (maximum4096 bytes), return the nonce plus module/source
identities, then exit. Parent sends one frame and EOF. Each has30-second lifetime,
stdout64KiB/stderr64KiB. No reconnect, second frame or retry. This checks module and
stdin loading only, not actual persistent worker/assembly integration.

Runtime executable hashing is limited to the four named binaries, each<=512MiB;
source materialization is limited to the22 pinned files. No recursive runtime or
unrelated user-data inventory is included. Imported dependency loading is observed
only at the named module level, not claimed to be a complete runtime closure.
No bytecode writes (Python -B).

## Private footprint and output binding

Exact reserved identity: `developer-a1-c0fa748e7bd046d0bcaa9a2c8d402a31` created once only AFTER
owner authority, outside every prior evidence root. Owner/current SID and SYSTEM
only, protected ACL; refuse occupied destination/reparse/ancestor ambiguity. Maximum
one directory,32 ordinary capture files,16MiB total; no preallocated journal,
backup, fixed container, lock, startup entry or live state copy. Capture records
include exact argv/payload/executable/source hashes, time, child identity, output
hash/length and terminal result. No delete/cleanup/rename or attempt reuse.
Interpreter temporary/cache/module-loader and CLI cache/log effects are native
possible writes beyond these capture files; they are not silently claimed absent.
No unrestricted persistent write probe is included.

Expected CLI JSON: marketplaces.name/root/marketplaceSource; installed and available
plugin entries pluginId/name/marketplaceName/version/installed/enabled/source/
marketplaceSource/installPolicy/authPolicy. Require exactly one target mapping and
retain contradictory/absent results as STOP, never infer an unregistered marketplace
from one absent guessed directory. A1 retains the reported target root/source mapping only; no native artifact-root
walk or Git-ref read is performed by this runner. Exact inventory, writer quiescence
and production observation callback remain pending before C/D.
The48-file release archive and actual installed/managed inventories remain distinct.

These observations admit only the observed CLI producer executable/source-review
relationship and concrete target root/layout mapping. A claimed producer SHA in an
injected envelope is not proof of actual acquisition. The production metadata
callback must later be bound to these observed commands and retained raw-result
hashes through the trusted host; that callback is not implemented/admitted by this
scope draft. Writer-quiescence provenance and actual native reader admission remain
required before C/D. Do not claim A1 closes them if evidence is missing.

No storage setup/preparation, listener stop/suppression, install, activation, merge
or other-machine action is part of A1. Submit this scope for review first; native metadata-producer binding remains a limit; any gate-opening revision must be reviewed before the
single owner decision is requested.


## Reviewed runner and authority boundary

`tools/release/codex_developer_a1_runner.py::run` is the A1-only entrypoint.
There is no standalone command that accepts an approval JSON file. The trusted
Codex host supplies `authorize(packet_sha256, "Developer A1 only")`, which must
fetch the exact protected-profile canonical request and positive owner28 event,
verify current revisions/deletion/time/scope/digest, and refuse stale/consumed
authority. It runs before filesystem access and immediately before each child.
A packet cannot supply this callback. As in the existing host, this is a trusted
cooperating-agent boundary, not cryptographic attestation against a hostile host.
The runner is callable without later edits; none was invoked during source review.

The private execution packet binds the exact capture parent/current owner SID,
four absolute executable paths and reviewed hashes, all22 source/scope byte pins,
accepted native effects, and scope-document SHA256. Its canonical sorted compact
JSON SHA256 is the owner decision target; the public proposal is not a substitute
for that fully populated private digest. No placeholder packet authorizes launch.
The exact parent/SID/runtime paths must be retained privately before that decision.
Source bytes must come from the reviewed Git blobs; runner verifies each byte hash,
materializes those bytes exclusively in the new capture and reads them back. It
never executes an autocrlf-transformed checkout. Captured source count22 plus six
result files and optional STOP is at most29 files; global16MiB capture bound applies.
No evidence root is created now. Native Windows capture uses CreateDirectoryW with
an atomic protected owner/SYSTEM DACL, refusing an occupied destination.

All child output is drained under one30-second deadline including stdin write/EOF,
pipe completion and exit. Results and failures preserve bounded raw output and child
identity. STOP on version/JSON/schema/nonce/mapping errors; no retry, rollback,
cleanup, kill or successor command. Stable postimage is not asserted on an uncertain
child. Primary error survives secondary failure-record writes. No inference from
these observations authorizes persistent worker or installation.
