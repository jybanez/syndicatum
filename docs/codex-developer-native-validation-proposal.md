# Native B fixed-storage validation - source/design only

## Current recommendation (#11585/#11588)

Implement and review a bounded file-level journal for the single Developer host.
No volume provisioning, elevation or global reservation service is a prerequisite.
This replaces the initial volume-first proposal; it does not approve native
validation, storage establishment, live preparation, installation or activation.
Both existing native B gates remain closed; old #11545/#11546 evidence is immutable.

## File design and documented limits

Separately establish two fixed-size private container files outside webroot/live
connector data, under a reviewed manifest pinning namespace, canonical root,
volume/file identities, sizes, ACLs and initial hashes. Use OPEN_EXISTING, writable
write-through handles denying other writers/delete. Allocate no new per-record
files, rename nothing, never extend/truncate during cutover. Occupied or partial
slots are immutable. The previous directory journal does not implement this design.

Each fixed slot carries framing/length, sequence, plan/authority/attempt binding,
previous-record hash, payload checksum and completion trailer. Write complete
reservation/intent -> file flush -> readback -> independent head-file write ->
flush/readback -> retain head digest outside the writer -> permit next bounded
step. Postimages use new slots. Missing/partial/torn record or head, exhausted
capacity, failed flush/readback, timeout or identity drift permanently stops;
never reuse slots, rebuild, resume, retry or restore queues.

Microsoft's [CreateFileW](https://learn.microsoft.com/en-us/windows/win32/api/fileapi/nf-fileapi-createfilew)
and [FlushFileBuffers](https://learn.microsoft.com/en-us/windows/win32/api/fileapi/nf-fileapi-flushfilebuffers)
document file/share/write-through and flush semantics. The recommendation uses
buffered write-through plus explicit flush, not alignment-sensitive NO_BUFFERING.
It does not infer atomic sectors or atomic multi-file commit. API success/readback
is not proof of arbitrary power-loss survival, namespace persistence or honest
hardware cache behavior. Storage establishment/reopen proves identity/usability,
not survival of every possible crash. If the owner requires stronger hardware or
rollback guarantees, independent durable infrastructure and separately scoped
validation remain an unavoidable decision, not an inert-source prerequisite.

| Option | Bounded failure model | Limit/authority |
| --- | --- | --- |
| Pre-established fixed file containers | Detect torn/incomplete frames and refuse replay after process interruption; order acknowledged file writes before later steps | Ordinary access to exact approved files; no namespace/power-loss/hostile-storage guarantee |
| Dedicated-volume barrier | Documented volume-wide FlushFileBuffers semantics | Admin and broad volume authority; still not power-loss proof, not a production-compatible shortcut; not recommended for this slice |

Separate files, directories or hashes do not establish independent physical failure
domains. Head independence here means a caller separately retains the expected
head digest and storage manifest in a trusted control context outside the mutable
journal bytes. Native identity/ACL enforcement and independent receipt provenance
are unimplemented. Same-device corruption or malicious same-user replacement can
defeat this logical separation; no physical resilience claim follows.

## Authority and single-host reservation

One fixed pinned storage manifest/namespace is selected by trusted orchestration,
never by an untrusted plan. All cooperating entrypoints use the same manifest.
Acquire its deny-share handle, reject any prior/partial bytes, then permanently
reserve the canonical owner-message ID before a modeled action. Crash releases
the handle but not the reservation. Another contender cannot open concurrently;
restart finds used storage and stops. Missing/replaced/rolled-back storage does
not authorize reinitialization. Storage setup has separate authority and cannot
be a retry path. No global reservation service is required by this threat model.

Trusted transport seam retrieves the canonical owner message through the exact
protected Developer identity. Strict expected receipt comparison binds server,
project2, owner28, addressee36, message/revision/body hash, plan digest, new attempt/
cutoff IDs and explicit scopes. Unavailable/changed/revoked/ambiguous authority
stops before intent. The model now explicitly validates expiry/not-before using a trusted injected
clock, reviewed mechanism/candidate, interruption acknowledgement and exact
operation context in a digest-bound reviewed plan; the fake seam remains neither
authentication nor a real canonical bridge. Retain raw
canonical tool response/provenance privately and its digest separately; never
accept a plan-provided boolean as owner verification.

Existing capabilities can contain duplicate notifications, cooperating local
contenders and process-crash retries. They cannot defeat malicious same-user/admin
bypass, bit-identical storage rollback, another host or revocation racing after
final canonical fetch. Explicitly retain these limits for eventual owner review.
If global consumption/instant revocation is demanded, an atomic consume capability
would be a separate owner tradeoff, and cannot atomically revoke an already running
local side effect. Do not silently require/implement a server service.

## Source implementation and synthetic validation (#11588)

`tools/release/codex_developer_fixed_journal.py` implements a fixed-slot protocol
model with in-memory pre-established record/head containers and fake transport
ONLY. Exact class checks reject arbitrary storage/transport implementations. No
paths are opened, no native API/network/process is called, no live storage is
initialized, and no command callback exists. Model results explicitly deny
mutation authority. The manifest/expected receipt/retained head must be supplied
by the trusted caller; same-caller fixtures do not establish external authenticity.
A new instance refuses all nonempty storage and any non-null retained prior head;
it is inspection/fail-closed only after interruption, never a resume mechanism.

Eleven inert fixture methods cover reservation ordering, two-contender exclusion,
occupied/partial storage, failure at every record/head write/flush phase during
reservation and intent, torn/hash-invalid slots, capacity/oversize, alternate
root/namespace/file identity/size, every changed receipt field, unavailable
authority, wrong retained head and operation replay. Failed bytes are preserved.
CI runs these separately from the retained-anchor 10 adapter/33 contract tests.
They prove model behavior only, not file flush or durable single-use reservation.

A future reviewed native implementation would create/change only two exact new
synthetic container files plus an approved private manifest and bounded result
files under a new UUID test root (128 MiB/512 files/10 minute ceiling). No existing
connector/config/credential/queue/startup/cache/old evidence access or modification.
Clock anomalies use fake inputs, never OS clock changes. ACL/path/reparse/handle/
flush/timeout cases use synthetic objects only. Partial/unknown outcome retains
objects and stops; no cleanup/retry/automatic child kill. Native API tests need
separate isolated-test authorization; gate opening and live preparation/install/
activation/restart each remain distinct later review/authority boundaries.

No environment provisioning, volume flush, elevation, native probe, canonical API
call or executable cutover packet is part of this source slice. Production
integration, physical durability and real canonical transport remain explicit gaps.

## Closed-gate Windows backend source (#11592)

`tools/release/codex-developer-fixed-storage.psm1` defines the Windows fixed-file
backend separately from the memory journal. The only exported dispatcher calls
an unconditional throw before any API initialization or storage access. It is not
wired to cutover. Internal backend definitions open existing files only with
FileMode.Open, ReadWrite, FileShare.None and WriteThrough; retain both handles;
pin handle volume/file identity, single link, canonical final path, fixed length,
protected ACL owner/grants and exact approved ACL digest. Reparse ancestors,
UNC/device/alternate-stream paths and live/webroot overlaps reject. No create,
truncate, resize, rename, volume handle, service or elevation operation exists.

Internal session logic copies the trusted manifest, checks both handles before
and after bounded fixed-slot writes, requires empty slots, flushes the exact file
and reads back exact bytes. Any uncertain write/flush/identity/readback failure
closes the session and denies further operations; bytes are not restored. Native
handle/ACL/flush definitions are unexecuted source. AST/closed-gate checks and a
fake API exercise session behavior without calling those definitions. This does
not establish production durability or native correctness. Session inspection
alone does not reserve an authority; native journal integration remains absent.

The trusted caller supplies a separately retained reviewed plan and manifest raw
bytes/digest at admission. It retains the initial reservation head before asking
for an intent; each result returns the next head, which the caller must retain
outside the mutable container before the next call. Handoff context must include
manifest/plan digests, authority/attempt IDs, sequence and head digest; mismatch,
missing prior handoff or process interruption stops rather than reconstructing
it from the journal. Current model tests a caller-held digest only; no independent
physical storage or authenticated persisted handoff is claimed. A future native
caller must keep both exclusive handles through the whole attempt, never reopen
per operation. The current gated dispatcher opens/inspects/closes only; it does
not execute the protocol or commands.

Official managed API basis: [FileStream.Flush(Boolean)](https://learn.microsoft.com/en-us/dotnet/api/system.io.filestream.flush)
and [FileSystemAclExtensions.GetAccessControl](https://learn.microsoft.com/en-us/dotnet/api/system.io.filesystemaclextensions.getaccesscontrol).
API documentation supports file-buffer and ACL retrieval semantics, not the
unexecuted adapter's correctness. Existing hardware/cooperating-host/revocation
limits remain unchanged. After source review, the minimal native-validation
proposal is exact hashed backend/harness plus two new synthetic pre-established
container files and private manifest/results, with size/time limits above. It
requires separate storage-setup/test approval, excludes all live objects and
native B paths, and is NOT an executable packet or current authorization.
