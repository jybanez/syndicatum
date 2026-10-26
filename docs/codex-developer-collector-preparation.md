# Integrated Developer collector and B/C/D preparation

Review input for Assessor #11731 in existing PR #160. Source is implemented and
CLOSED. This document and the adjacent pilot admission JSON are one packet, not
execution authority. Native acquisition, installation, activation, merge and
remote-machine work remain unauthorized.

## Retained evidence and corrected roots

Owner request 11728 / event 11729 authorized completed A1. Report 11730 records
six exit-zero commands, seven authority receipts, 29 files /307295 bytes and
fingerprint `d3b478c05817f426b78f3e5324d068924b6cef00da5ab3a2c973ac49f38e42f1`.
Assessor accepted Developer-observed completion, without independent private
inspection. All successful/stopped captures and proposals are consumed: no
rerun, reuse, cleanup, amendment or retroactive admission.

Correction to the initial note: A1 plugin list `source.path` is the marketplace's
`plugins/codex` source directory. It is not the installed cache. Retained data was
re-read without a new walk/probe to establish that relationship. No deployment
used the mistaken cache interpretation.

`retained_root_map` derives the following six roles from retained results 1/2
and immutable upstream layout constants. Paths remain private; canonical map
SHA-256 is `16c23f0c3a50008f75fbeee852f660f9646285bc99905c009b17720317e58047`.

| Role | Mapping | Evidence |
| --- | --- | --- |
| managed | A1 marketplace root | Retained observation |
| plugin_source | managed / plugins / codex | Retained observation |
| installed_027 | Codex home / plugins / cache / syndicatum / codex /0.2.7 | Source-derived scope; actual inventory pending |
| installed_0210 | Same cache base /0.2.10 | Source-derived scope; inventory pending |
| marketplace_staging | managed parent /.staging | Source-derived scope; fresh state pending |
| cache_marketplace | Codex home / plugins / cache / syndicatum | Source-derived scope; fresh state pending |

Retained result 1: 1324 bytes, SHA-256
`fdb41dfe306df541c8db84a8773d58b31a445bc9cfaab0f2c35f39079ea066b9`.
Result 2: 13954 bytes, SHA-256
`6a16fd4282fabc7576544d09555c43c0d3813288748da0a68aa517a27ec41083`.
The CLI installed boolean/version does not establish complete cache bytes. The
48-file candidate archive at `9141daa08d896f2524be30b96d01ab2a14398757`
remains separately pinned; it never substitutes for installed inventory.

Derivation uses official OpenAI source commit
`6905ecede7816e91373e9eabda3f6b0368fc747b`: `core-plugins/src/installed_marketplaces.rs`,
`marketplace_staging.rs`, `store.rs` and `core-plugin-common/src/installed.rs`
under `codex-rs/`. Cache staging prefixes `plugin-install-` / `plugin-backup-`
are immediate children of cache_marketplace; marketplace staging includes all
immediate entries. Unexpected entries block installation, never authorize cleanup.

## Implemented acquisition and provenance

Pilot JSON pins exact staged Git blob bytes for collector, producer, Windows
observer and their dependencies. Current pins do not alter the retained A1
payload's 22 source pins. Its four executable hashes are retained inputs requiring
execution-time recheck; CLI version matching is not binary/source equivalence.

`NativeInstallReader` reuses accepted `check_nonreparse_chain`: lstat plus direct
Windows attributes for all ancestors including volume root. Roots, tree entries,
content and Git refs are checked before reads, with content/ref metadata checked
afterward. All reparse tags, unavailable attributes, unexpected inventory or drift
stop. Dangling leaves never mean absence. Git refs <=1 MiB; artifacts <=16 MiB,
512 files /256 directories. These are repeated path checks, not atomic handle
race prevention.

`NativeMetadataProducer.collect` owns acquisition. Production assembly constructs
it directly; schema 2 rejects JS host fact-map challenges. It binds challenge
sequence/nonce, context/root map, own source hash and reviewed runtime pins.
Independent collector reads run inside two Windows process/runtime observations,
including installed files and managed HEAD/ref reads. Managed presence/absence
is independent of registration, including partial failures. Metadata binds
artifact inventory, managed state, unrelated inventory and CLI results by hash.
Native partial-failure collection gets fresh proof, not a cached writer list.
Missing/mismatched evidence returns unavailable/STOP.

Unrelated evidence includes all returned marketplace/plugin registration fields.
Artifact layouts must cover every installed sibling plugin within the affected
syndicatum cache region; an omitted sibling is a hard stop. Other marketplace
caches are outside the fixed commands' source-reviewed write region: their
registration metadata is observed, without claiming their entire contents were
hashed. This is not a global cache byte-integrity attestation or a broad cache walk.

Only two read-only CLI commands exist, in order per observation:

1. `plugin marketplace list --json`
2. `plugin list --json`

They use pinned executable, existing neutral cwd, hidden shell-free Popen, stdin
EOF, concurrent bounded pipe drains, 8-second child and 30-second observation
deadlines. Stdout <=262144 bytes; stderr <=65536. Nonzero exit, warning, malformed
JSON, timeout or bounds failure consumes the attempt. No retry, kill, cleanup or
successor. Bounded partial prefixes and unverified Popen PID remain in private
memory; no child OS image/creation-time verification claim.

CLI list omits configured ref/sparse. Observed marketplace postimage is URL plus
independently resolved HEAD. Exact ref/sparse remain verified dispatch argv/hash,
not fabricated configuration observations. No config-layer investigation or
direct configuration parsing was added.

Loader/effect scope is explicit: Python is launched with `-E -s -S -B`, so no
automatic bytecode writes; existing bytecode can still be read and is covered by
the closure. Normal interpreter/DLL loading and handle queries occur. CLI list
can read effective configuration/auth and may perform native metadata refresh,
cache/log writes or network access. "Read-only" describes the requested command,
not a guarantee of zero incidental native effects. The owner scope must include
those effects. No such CLI/probe was run during this source preparation.

## Windows observation boundary

Toolhelp enumerates <=4096 processes; <=128 members of fixed source-declared
CLI/Git/script-host families are queried. QueryFullProcessImageNameW and
GetProcessTimes obtain image/creation identity. Only exact admitted image paths
are hashed (<=16 distinct images, <=512 MiB total per snapshot). Unknown images,
permission errors, vanished processes, duplicates or incomplete enumeration stop.
Exclusions require exact live PID/parent/creation/image/path tuples. The observer's
own independently queried identity must be excluded explicitly; an empty process
result never proves quiescence. No name-only/ancestor-only/stale-PID exemptions,
command-line reads, process mutation or process-memory reads.

This is independent bounded family observation, not proof against arbitrary
hostile writers or future starts. Admission must accept its coverage and justify
each coordinator/host exclusion. Unproven coverage remains unavailable, never
waived with an empty list.

Runtime closure covers loaded Python files, existing loader bytecode, builtin/
frozen identities and current-process PSAPI modules. No OS/runtime directory walk.
Exact files, sizes, hashes and absent bytecode paths are pinned (<=256 files,
<=256 MiB/file, <=512 MiB total), with matching before/after observations. PSAPI
excludes DATAFILE-only modules and arbitrary injected executable memory; this is
not blanket OS attestation.

Disabled discovery proposes pins only for loaded files under <=3 exact admitted
roots: A1 Python installation, new private source/capture root, and exact Windows
System32 root. No out-of-scope origin is hashed. It repeats closure verification
and returns `PROPOSED_NOT_ADMISSION`; it cannot approve the later binding.
Unknown origins/required roots stop. Discovery and its file reads are NEW owner
scope, not authorized or executed during this preparation.

The separate disabled process-origin discovery mode returns queried identities
and at most 16 unadmitted image paths privately, without opening those unknown
images. Its wrapper is always `PROPOSED_NOT_ADMISSION`; it cannot be passed to
the production writer validator. A missing image hash is not quiescence. Exact
paths/exclusion rationale must be reviewed before any production observation.

Win32 sources: [Toolhelp](https://learn.microsoft.com/en-us/windows/win32/toolhelp/tool-help-functions),
[PSAPI](https://learn.microsoft.com/en-us/windows/win32/api/psapi/nf-psapi-enumprocessmodulesex).
Native API paths remain unexecuted. Inert tests prove orchestration, refusal and
policy behavior, not native Windows acceptance.

## Proposed acquisition, B setup and C/D boundary

Before mutation, obtain reviewed installed/unrelated layouts, actual runtime
closure, fresh process identities/exclusion rationale, source/runtime pins,
capacity and timing measurements. Missing fields remain unavailable. At most
six normal observations plus one terminal-failure observation: 14 fixed CLI
queries, 14 process snapshots and 14 verified runtime observations. Discovery is
separately bounded to two <=512 MiB passes. Unrelated artifacts: <=16 exact
layouts, each <=512 files /256 directories /16 MiB. Staging: <=512 immediate
entries per two scopes, <=128 reported staging entries, no recursive walk.

Proposed new sibling leaves under SAME retained existing LocalApplicationData
parent: `developer-0210-11731-{capture,backup,writer,head,receipt}-v1`.
Canonical private proposed-root-map SHA-256:
`7291f5ca54defc02f041337491f2d360f76b8e753f24bac4068762f7327748ce`.
These are proposals, not allocated or checked absent. Occupancy means STOP,
never reuse/rebaseline. Parent path hash:
`77c775d422390019b43d9f0fc693eadaf4b714cdaafa208b6eed5f1dc8d52d91`;
retained owner SID hash:
`849c72d8778132055b8c4c3d1dbafcf424d83531b86a6eaa2177813a16b7c95e`.
Exact paths/SID stay private and require fresh checks. Use already reviewed B
mechanisms for atomic owner/SYSTEM-only ACL roots, identities/final paths,
byte/readback manifests and original ACL evidence. No credentials/private
configuration go to GitHub or shared project storage.

Capacity: writer/head each 512 x65536 x2 =67108864 bytes; receipt 128 x49152 x2
=12582912; containers total 146800640. Plus logical evidence 33554432 and
collector/capture allowance 16777216 gives 197132288, below private cap
201326592 by 4194304 bytes. External backup has a separate 1073741824 cap.
This is arithmetic, not observed free space/allocation. Actual backup totals,
manifest cardinality and journal publication/slot counts must be admitted before
setup. Raw queries remain bounded private memory until an explicitly authorized
durable evidence sink is bound; journal retains envelope/input hashes. Do not
claim raw queries are already durably captured.

The 120000 ms pilot/freshness bounds remain. Observation deadlines are checked
between native calls; filesystem/API stalls are not asynchronously interrupted.
No native timing measurement exists. Read bounds do not prove the time budget;
inability to meet it blocks admission rather than widening limits/retrying.

Eventual ONE B/C/D decision must bind admitted acquisition, bounded setup,
verified external preimage backup, same-session listener suppression/stop and
exact remove/add/plugin-add sequence. CLI internal staging/cleanup/replacement
effects remain explicit; no outer replay/rollback. Owner controls normal Codex
close/open and separate notification/lifecycle acceptance. Source/runtime/layout/
authority/capacity drift must stop before mutation. No native gate is opened.
