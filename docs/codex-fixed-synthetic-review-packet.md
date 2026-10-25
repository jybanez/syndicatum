# Fixed storage synthetic native-validation candidate (#11594)

SOURCE ONLY, UNEXECUTED. The accompanying JSON pins exact LF harness/backend
bytes and measured local runtime files. Runtime hashing is read-only provenance,
not a native storage probe. No owner request is issued by this document. The
harness and exact-head CI require review before a concrete isolated-test approval
request may be sent. Any source/runtime change invalidates those pins and review.

## Scope and handoff

One new private temporary tree on Developer only, with the exact root leaf in
the JSON packet under the operator's existing TEMP directory. No other host,
connector/profile/cache/credential/Task/Run/process state is read or changed.
Source/runtime files and ancestor attributes are read for admission; the current
user SID is read to set the synthetic ACL. No volume handle, elevation, service,
network/canonical access, process management or cutover command exists here.

The harness defaults to refusal. A future trusted operator would have to verify
the canonical owner message, exact packet/source/runtime hashes, source review
and CI success, then supply the isolated-run switch, exact new root, source pins
and approval-message reference in a clean NoProfile PowerShell invocation. Those
arguments are an operator handoff, NOT an implemented canonical verifier. The
script cannot prove approval merely from a message number. No instruction to run
it is being supplied now. It invokes internal fixed-storage functions within the
module, never either exported live dispatcher; their native gates remain closed.
Only this separate isolated harness can reach the backend definitions, for its
new synthetic objects, after the later explicit approval. No real cutover path
is wired or opened.

## Exact setup writes, API scope and limits

Create the new root without Force; existing root is a permanent stop/no-retry.
Set only its new ACL owner to the current SID, disable inherited rules and allow
current user/SYSTEM. Exclusively create `attempt.json`, two 8192-byte zero-filled
containers, `manifest.json`, and `result.json`; no replacement. Protect only the
new container ACLs. No existing file is resized or truncated. Managed compiler scratch is directed to the same tree using process-local
TEMP/TMP, restored on exit, but NO scratch footprint is approved. Any compiler
file or directory is unknown and causes STOP, even under the byte/count limits.
Only the five named regular files are allowed; result files <=64 KiB and total
bytes <=1 MiB. No automatic deletion occurs.

Allowed operations: bounded read/hash of pinned sources/runtime; path/ancestor
attribute reads; exact new directory/file creation and ACL setup; FileStream
Open/CreateNew/Read/Write/Seek/Flush(true)/Dispose on approved synthetic files;
handle identity/final-path APIs GetFileInformationByHandle and
GetFinalPathNameByHandleW; handle-backed GetAccessControl; current SID and
monotonic Stopwatch. No CreateFile volume call, SetLength, rename or cleanup.
Module Add-Type loads the reviewed handle helper in memory; its platform behavior
and whether it produces any unknown scratch are unverified. Unknown scratch
blocks the candidate; it is never normalized as an allowed exception.

The 120-second deadline is checked between stages, not a guarantee that a blocked
OS call can be interrupted. A hung native call has uncertain outcome: no new
attempt, automatic kill or retry. An operator would retain the process and files
for separately authorized handling. At each check, inspect the root for reparse status, enumerate direct children
ONLY (up to33 observations, then stop) and record names/types/reparse flags/lengths.
Never descend into directories or inspect reparse target lengths. Reject every
unknown/duplicate name, directory, reparse entry or invalid per-file length.
Retain this observed inventory in the result, including rejected entries. These
stage checks do not intercept runtime-internal writes before they happen.
If strict hard real-time/resource containment is required, this harness cannot
provide it without a separately reviewed supervisor. Do not claim that limit met.

## Procedure and plain expected outcomes

1. Verify scope/pins/root absence, create private new objects and retain the
   approval-reference attempt record. Any mismatch or ACL/setup failure stops.
2. Establish the two synthetic file identities/lengths/ACL digests and retain
   their manifest. This establishment is test-only, not production storage setup.
3. Open both existing files once with exclusive write-through handles; keep that
   same session across both slot writes. Expected: both exact pins accepted.
4. Write/read back synthetic record slot 0 then synthetic head slot 0. Expected:
   exact bytes and unchanged identities/lengths/ACLs. These are test patterns,
   not a live authority or mutation journal.
5. Capture both full container byte arrays under the held handles, then attempt
   overwrite. Expected: exact FixedStorageOccupiedSlot error classification and
   terminal session. Every other exception is STOP, never expected success.
   Independently read both files after rejection through read-only pinned
   identity/length/ACL handles; require both full arrays unchanged. Retain their
   before/after hashes. Post-rejection reads do not reopen a writable session.

Failure at any stage retains partial evidence and STOP status. There is no
rollback, recapture-in-place, repair, resume, cleanup or retry. The result file
contains generic status only; it does not expose raw private machine configuration.
If result writing or handle close also fails, retain/rethrow the ORIGINAL error
record, report secondary failure separately and preserve the partial tree; never
try a new destination. Failure-result writing may write only a bounded new known
result.json without traversing rejected objects; it cannot turn STOP into success. External trusted orchestration retains packet digest,
source/runtime pins and result digest; the test cannot fabricate independent
provenance. Physical failure-domain independence is not asserted.

## Inert composition and acceptance limits

The existing PowerShell fake-API suite now consumes exact Python memory-model
reservation/intent frames, writes them through the fake backend contract while
retaining one session, and matches the caller-retained final head digest. No
native backend/harness is invoked by that test. Slow-evidence regressions require
fresh authority/time after intent publication; expiry/change then consumes the
intent and stops before any modeled admission can be returned.

AST checks parse this harness only. Local inert success does not prove Windows
ACL/identity/sharing/flush behavior. Even a later native pass would prove only
observed API/readback and rejection behavior, not hardware power-loss resilience,
malicious same-user resistance, instant revocation or production readiness. The
canonical retrieval bridge and production integration remain absent.

After exact-source review and green CI, prepare ONE owner request binding this
exact packet and new tree to synthetic setup/ACL/file API testing only. Do not
combine it with connector suppression/listener stop, install, activation or
restart authority. That later approval request must state the cooperative timing
limit and uncertain-call handling above. Nothing here authorizes execution.

## Targeted correction #11597

The typed occupied classification and complete before/after comparisons are
shared pure helpers tested inertly for unrelated errors and unexpected changes
to either container. Inventory validation is likewise shared with inert fixtures
for unknown-under-limit, directory and reparse objects. No recursive enumeration
is used by the harness. Runtime compilation scratch is unresolved and explicitly
unapproved, so its appearance stops instead of expanding the allowlist. Source
hashes in the accompanying packet supersede the earlier candidate pins. The
harness is still parsed only, never invoked (including refusal mode), and no
owner execution request has been issued.

## Native attempt11616 and current admission blocker

The separately approved single invocation stopped during backend session opening
because a dynamic callback could not resolve module-private Assert-FixedPath.
See codex-synthetic-native-stop-11616.md for immutable evidence and source-only
correction. The five-file tree now exists and is retained. No current packet may
reuse/clean/rename it; no replacement root has been selected. The changed backend
pin describes a new source candidate, not a refreshed11616 approval. A later
isolated test requires exact-source review/CI and a new explicit owner decision
on the test-tree identity. No second invocation or native validation is authorized.

## Current draft under #11618 (supersedes earlier candidate identity)

The JSON now proposes distinct leaf
`syndicatum-fixed-validation-55a445a3f2f54df2b3846ec1ea63f1dc` as SOURCE-ONLY draft.
It does not exist by any action taken here; no new tree has been established or
old tree renamed/deleted. The old leaf remains separately identified as immutable
11616 evidence. The harness accepts only the new drafted leaf and a later
approval-message reference; neither is authorization. Exact-source review, green
CI and a fresh concrete owner approval must precede any later native invocation.

The actual New-FixedWindowsApi constructor now captures bound scriptblocks for
every private/native primitive. Its new inert regression replaces ALL11 primitive
functions before construction, invokes returned Open/Inspect/Read/Write/Flush/Close
callbacks outside the defining module invocation, checks every expected stub is
reached and verifies original error IDs propagate (including validation from both
Open and Inspect). It restores stubs afterward. No native initializer, filesystem
primitive or harness is invoked by that regression. Existing fake storage contract
tests remain separate; neither class of test proves native correctness.

## Current STOP state after11628 (supersedes draft admission status)

The separately approved55a445 attempt ran ONCE and failed during path traversal;
its five-file tree now exists as immutable evidence. See the11628 STOP record.
The current packet is blocked; no next identity is selected and no retry is
allowed under11628. Source now fixes provider-only parent traversal and pins a
new setup's normalized handle name explicitly. Only inert actual-helper/constructor
coverage was added; no native rerun or old manifest update occurred. Both prior
trees remain untouched. Further source review/CI/owner authority is separate.
