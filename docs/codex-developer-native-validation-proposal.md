# Native B validation proposal â€” source/design only (#11582)

No authorization, runnable packet, native probe or gate change is contained here.
Developer is the sole eventual connector target. Old #11545/#11546 evidence is
immutable. PR #160 correction head 706574293809f1c17fed890477c4ded089cb85e8
passed 33 contract, 10 inert adapter and PowerShell closed-gate/pure-anchor checks;
protected run 40156975665 was still in progress when this proposal was authored.
A green result must be retained separately; none is inferred from prior heads.

The feasibility recommendation below supersedes the volume-first recommendation
and globally single-use service requirement in the initial proposal. Both remain
comparisons, not new prerequisites.

## 1. Replace the unsupported durability assumption

Reject directory-handle FlushFileBuffers as an established publication guarantee.
Microsoft documents FlushFileBuffers for writable file handles, and separately
explicitly supports flushing all open files through a volume handle with
administrative privileges. The proposed supported alternative is a *dedicated
isolated test volume*, with a volume flush after each journal record publication
and after its disjoint head publication. This is a proposed replacement, not an
implemented fallback. Never target the Developer system/live data volume under
an isolated-test authorization.

Order: exclusive new attempt directory/record -> complete write -> file flush ->
volume flush -> exclusive disjoint head write -> file flush -> volume flush ->
independent reread/hash/identity verification -> advance in-memory acceptance.
The journal and head roots must both be on the approved test volume; reject any
other volume, reparse traversal, path escape or shared production content. A
volume flush affects every open file on that volume, which is why a dedicated
volume and explicit elevated-test authority are mandatory. No automatic elevation,
privilege adjustment, volume creation/mounting, administrative service or fallback
is allowed. A missing approved isolated volume/elevated test environment is a
capability blocker, not permission to flush the host volume.

Any failed write, short write, denied/unsupported flush, failed reread, identity
change, timeout or partial publication permanently stops the attempt. Retain raw
failure and partial files; do not clean, resume, overwrite or publish a success
head. A record without its head cannot authorize the next action. Readback and
process-crash fixtures do not prove power-loss resilience. Hardware/controller
cache behavior and filesystem support remain validation limits, even after API
success. An actual live durability design must receive separate platform review;
a dedicated test-volume result cannot authorize a live-volume flush.

Primary basis (claims bounded to the documented API semantics):
- [FlushFileBuffers](https://learn.microsoft.com/en-us/windows/win32/api/fileapi/nf-fileapi-flushfilebuffers): file flush semantics, return/error behavior, volume flush and administrator requirement.
- [CreateFileW](https://learn.microsoft.com/en-us/windows/win32/api/fileapi/nf-fileapi-createfilew): handle access/share flags and write-through limitations.
- [File buffering](https://learn.microsoft.com/en-us/windows/win32/fileio/file-buffering): unbuffered alignment requirements and hardware caching caveat.

Unbuffered/write-through file I/O is a documented alternative for file contents,
but is not substituted here for proof of newly created directory/name durability.
No rename/atomic-sector/power-loss guarantee is inferred from those flags.

## 2. Canonical owner-message verifier bridge

Proposed interface: verify_owner_authority(message_id, plan_sha256, scopes,
context) -> retained receipt or typed rejection; no boolean supplied by a plan.
The bridge uses the exact protected Developer profile through the installed
profile-bound get_message tool, never a token/file parser or another identity.
It must read the immutable source-review reference and exact owner message from
the canonical server. A trusted host-side orchestrator, separate from untrusted
plan data, performs this retrieval and comparison. The current Python callback
is only a seam, not this implemented bridge.

Required explicit structured authority fields: server/project/owner participant
28, addressed Developer participant36, authority kind, exact reviewed mechanism
head and candidate, canonical plan digest, distinct attempt/cutoff IDs, exact
allowed operations, notification-interruption acknowledgement, expiry, and
single-use reservation reference. Free-form ambiguous approval is rejected.
Owner identity comes from the canonical sender record, not body text. Reject
deleted/edited/revoked or missing context, wrong project/sender/addressee, expired
or exhausted IDs, unsupported scope and unknown fields; #11545 cannot be reused.
Immediately before first mutation re-fetch the canonical message and compare its
revision/body digest with the retained receipt. A changed/unavailable response
stops; no cached offline approval or automatic replay.

Receipt evidence: tool operation/profile identity (non-secret), canonical IDs,
server/project/sender/addressees, created/updated/revision metadata, exact raw
response/body digests, normalized explicit scope comparisons, plan digest,
reservation result and retrieval time. Keep raw response privately and bind its
receipt hash into the disjoint journal head. Plan data cannot fabricate retrieval
provenance. Race-free single-use reservation/revocation semantics require an
authoritative server-side capability; if unavailable, report that gap rather
than treating a local file or an inert callback as globally single-use authority.
An isolated test uses a fake transport and fixture authority; it can validate
rejection logic only, never establish real owner approval.

## 3. Exact proposed isolated-test objects and boundaries

A later approved manifest must fix one new UUID, dedicated volume identity,
external private root, hashes of the test harness/runtime and size/time ceilings.
Proposed volume-local tree (all new, synthetic, no live preimages):

```text
native-b-validation-<uuid>/
  manifest.json
  fixtures/{tasks.json,runs.json,locks.json,state.json.participant-36}
  journal/records/<sequence>.json
  heads/<sequence>.json
  transport/{plan.json,stdout.bin,stderr.bin,uncertain-pid.json}
  results/{observations.json,failures.json,summary.json}
  path-tests/{original.bin,replacement.bin,junction-target/}
```

Maximum 128 MiB, 512 files, 10 minutes per approved invocation; reaching a limit
stops without deletion. Private ACL: current test operator and SYSTEM only;
negative ACL/reparse fixtures must stay under this new test root. Any inability
to create such a fixture is an explicit skipped/blocked case, never a pass.
No actual Task Scheduler or HKCU Run entries, connector paths, profile/config,
credentials, queues, installed caches, old evidence, startup methods or production
processes may be read or changed. Task/Run collections remain synthetic maps.
A future owned-process fixture would create only a separately pinned dummy child
whose command/handle/start identity is recorded at spawn; never select by name
or PID alone. No network requests except separately scoped canonical reads;
no message posting, notification/delivery or plugin actions.

Cases: approved/foreign owner and inherited/writable ACLs; ancestor junction and
path replacement; file-handle/path identity swap; denied sharing/short writes;
clock backward/forward/QPC discontinuity through injected clock inputs (never
change the OS clock); missing/wrong volume or access rights; flush failure at each
record/head barrier; truncated record/head, extra files and tampered external
head; child timeout with PID retention/no kill/retry; process termination between
publication phases only if separately authorized. Native capture tests use only
synthetic files. Compare the complete synthetic tree and approved object manifest
before/after each case; unexplained objects/access or partial outcome stops.
No automatic cleanup, rollback, retry or host reboot. Abrupt-power-loss tests
require a separate disposable environment and separate destructive-test approval.

Existing inert suites already cover transition drift, terminal replay, partial
suppression, timeouts, raw identity and malformed-state failures; do not represent
these as native API validation. Native Task/Run suppression correctness and
production end-to-end readiness remain outside this isolated synthetic scope.

## 4. Explicit authorization ladder and current capability gap

1. Commercial source/design review of this proposal and a future exact harness.
2. Owner approval solely for an isolated test: explicit dedicated volume,
   elevation/flush breadth, new object manifest, dummy child and interruption
   policy. Do not create a VM/VHD/volume or elevate implicitly. No such environment
   is currently established or approved; its provisioning is a separate owner
   decision. Until supplied, native durability validation is BLOCKED.
3. Separate approval for any production-compatible durability design and its
   exact implementation; do not transfer isolated results to the live volume.
4. Entirely new owner approval for live connector-only suppression, exact owned
   listener stop and notification interruption/cutoff capture, after reviewed
   source and validation. This is distinct from installation approval.
5. Separate install, activation and restart/reopen authorities, each explicitly
   bounded. No test or source review implies any of them.

Both native gates remain closed. This proposal deliberately leaves the live
adapter unexecutable and does not create a fresh cutover packet or private cutoff.

## 5. Compact feasibility recommendation (#11585 — controlling delta)

**Shortest path:** implement/review a file-level fixed-storage journal and a
single-Developer-host exclusive authority reservation; validate using synthetic
files only after separately scoped authorization. Do not provision a volume or
server service. This is a design recommendation, not an operational acceptance.

| Design | Bounded guarantee sought | Remaining limit | Host authority |
| --- | --- | --- | --- |
| Pre-established fixed-size record/head containers; write-through file handles plus explicit FlushFileBuffers and readback | Complete acknowledged file writes before the next operation; detect torn/partial/hash-invalid records and fail closed after process crash | No atomic multi-file commit, newly created directory durability or dishonest-device/power-loss guarantee; namespace/storage establishment is a separate prerequisite | Ordinary write access to two exact approved private files; no volume/admin scope |
| Dedicated-volume barrier experiment | Documented volume flush covering open files on that volume after publication | Still not hardware/power-loss proof; cannot transfer result to production volume | Broad volume access/admin and environment provisioning; disproportionate for this one-host scope |

File design: separately establish one new bounded journal container and one
physically disjoint head container outside webroot/live connector state. Pin
volume/file identities, ACLs, lengths and initial hashes in the reviewed storage
manifest. Open with OPEN_EXISTING, GENERIC_READ/WRITE and deny other writers/delete;
reject absence, replacement, wrong size or unknown bytes. No per-record creation,
rename, extension, truncation or directory publication during cutover. Reserve
fixed slots inside these existing files: length, sequence, plan/authority/attempt,
previous hash, payload, checksum and completion trailer. Never overwrite an
occupied or partial slot. Write complete intent slot, flush the file, read/verify;
then write its independent head slot, flush/read/verify; only then permit the
bounded action. Postimage is a separate record, not an update to intent. Any
partial head/record or missing confirmation permanently consumes this attempt;
no recovery path repeats its mutation. Retain failed bytes and external receipt.
Capacity exhaustion or uncertain flush stops before the next action. The existing
per-record/directory journal implementation does NOT yet implement this design.

The proposal uses FILE_FLAG_WRITE_THROUGH plus explicit file FlushFileBuffers;
it does not require NO_BUFFERING, alignment-sensitive I/O or assume sector atomicity.
Microsoft documents that write-through requests cache flushing but hardware may
not support it; successful API/readback is bounded evidence, not proof against
power loss or hostile storage. The owner must choose whether that documented
file/device failure model is sufficient. If guaranteed persistence despite device
cache/power loss or storage rollback is required, this source-only design cannot
prove it: specify independently durable storage/hardware and a separate validation
scope. Do not silently replace that requirement with an elevated volume flush.
Pre-establishment/reopen validates identity and usability only; it cannot prove
namespace survival over arbitrary power loss. No file has been established here.

### One-host no-replay without a new server service

For cooperating runners on this single host, a permanent authority-ID reservation
in the same pre-established journal, combined with a deny-share writable handle,
serializes contenders and consumes authority before first mutation. Bind server,
project, owner message ID/revision/body hash, plan digest, mechanism head and
attempt ID in the reservation; flush/read/head-confirm it first. A second process
cannot acquire the handle concurrently; after a crash it finds any reservation,
intent or partial record and STOPS, even if no mutation is confirmed. Never create
a new attempt merely because the old one failed. All entrypoints must use the
same pinned storage manifest and authority-key namespace; caller-selected alternate
journal roots are rejected. Missing/replaced/rolled-back storage is a hard stop,
not permission to initialize a clean journal. Initialization is a separate approved
step, never part of retry. Exact canonical owner-message revalidation remains
mandatory before first mutation and at authority boundaries; unavailable, edited,
revoked or mismatched approval stops. Receipts retain canonical tool provenance.

This contains accidental concurrency, duplicate notification/command replay and
process-crash restart among cooperating local runners using the pinned journal.
It does NOT contain a malicious same-user/admin runner bypassing the protocol,
restoring a bit-identical old storage image, a second independent host, or owner
revocation racing between final fetch and an irreversible command. Those threats
also defeat a plain local exclusive attempt directory; the existing canonical
read API supplies no atomic approval-and-mutation transaction. No evidence says
these stronger threats are in scope for the single Developer attempt. Therefore
server-side global reservation is withdrawn as a mandatory prerequisite. If the
owner requires global consumption/instant revocation, the minimal gap is an atomic
server compare-and-consume authority token (and defined revocation semantics), not
a general new service; live side effects still cannot be made atomic with a remote
revocation. Otherwise recommend explicitly accepting the narrow revalidation
race/cooperating-host boundary and using the existing capabilities. No server
change is implemented or requested here.

Next source slice, after review: replace directory barriers with the fixed-file
model, implement strict canonical-message receipt comparison through a trusted
transport seam, and add inert two-contender/reserved-ID/crash-at-every-phase/torn
slot/alternate-root/revocation-change fixtures. Synthetic native tests would cover
only newly approved file containers/ACL/path/handle semantics, no Task/Run or live
collector. This is one bounded path; volume provisioning, global service design,
cloud/other-machine audits and gate opening are unnecessary to that source slice.
