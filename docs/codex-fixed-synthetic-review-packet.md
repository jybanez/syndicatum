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
new container ACLs. No existing file is resized or truncated. Managed compiler
scratch is directed to the same tree using process-local TEMP/TMP, restored on
exit. Any scratch remains evidence; no automatic deletion. All recursive objects
count toward the packet's 32-object/8-file/1-MiB ceiling; result files <=64 KiB.

Allowed operations: bounded read/hash of pinned sources/runtime; path/ancestor
attribute reads; exact new directory/file creation and ACL setup; FileStream
Open/CreateNew/Read/Write/Seek/Flush(true)/Dispose on approved synthetic files;
handle identity/final-path APIs GetFileInformationByHandle and
GetFinalPathNameByHandleW; handle-backed GetAccessControl; current SID and
monotonic Stopwatch. No CreateFile volume call, SetLength, rename or cleanup.
Module Add-Type loads the reviewed handle helper in memory; its platform behavior
and scratch footprint are themselves unverified until an approved test.

The 120-second deadline is checked between stages, not a guarantee that a blocked
OS call can be interrupted. A hung native call has uncertain outcome: no new
attempt, automatic kill or retry. An operator would retain the process and files
for separately authorized handling. Count/size checks detect excess at stage
boundaries; they do not intercept arbitrary runtime-internal writes in advance.
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
5. Attempt to overwrite occupied record slot 0. Expected: rejection, terminal
   session and no further writes. Record the bounded result; preserve all bytes.

Failure at any stage retains partial evidence and STOP status. There is no
rollback, recapture-in-place, repair, resume, cleanup or retry. The result file
contains generic status only; it does not expose raw private machine configuration.
If result writing also fails, retain the original error and partial tree without
trying a new destination. External trusted orchestration retains packet digest,
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
