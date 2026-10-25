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

These three frozen source payloads remain unconditionally gated before imports and
stdin. An owner-approved, source-reviewed admission revision is required to permit
execution; this packet does not open them. They import the exact named modules,
accept one48-hex nonce frame (maximum4096 bytes), return the nonce plus module/source
identities, then exit. Parent sends one frame and EOF. Each has30-second lifetime,
stdout64KiB/stderr64KiB. No reconnect, second frame or retry. This checks module and
stdin loading only, not actual persistent worker/assembly integration.

Loaded code dependencies only: max512 files/256 directories/256MiB total; no full
runtime tree or unrelated user-data inventory. No bytecode writes (Python -B).

## Private footprint and output binding

Proposed identity: `developer-a1-<fresh 128-bit random hex>` created once only AFTER
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
from one absent guessed directory. Only target artifact roots may then be enumerated
read-only, bounded512 files/256 directories/16MiB, rejecting reparses and protected
configuration/authentication files. Target mapping must come from observed CLI output,
not a caller-selected root. Git HEAD/ref observation is bounded1MiB per ref file.
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
