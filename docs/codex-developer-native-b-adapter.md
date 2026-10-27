# Developer B native preparation candidate

Current proposed journal/authority route: see
`codex-developer-integrated-preparation.md`. Preparation requires the fixed-file
journal/admission integration; the old directory-flush dependency below is
historical context, not the selected path. Native gates remain closed.

SOURCE ONLY; not deployed or executable in this revision. Python's
`NATIVE_B_ENABLED` is false and the PowerShell module's exported dispatcher
unconditionally throws before any native collection/action. Opening either gate
requires a different reviewed exact source head; neither an environment variable
nor a plan field can enable them. There is no main/install/activation entry point.

## Concrete bindings

`codex-developer-native-b.psm1` implements Windows API-backed snapshots, startup
suppression, exact process stop, cutoff capture and a required evidence barrier.
Only the gated dispatcher is exported. It pins the data root to this user's
Developer installation and the service entry to its background runtime. Paths
with reparse ancestors are rejected. No other-machine parameter exists.

- Snapshots export complete Task XML and both HKCU Run views, query relevant
  processes through CIM plus process-handle start time/image identity, and query
  each lock PID independently rather than infer liveness from an empty aggregate
  list. All nonvolatile/nonroute protected files are enumerated and hashed; the
  plan cannot hide an extra static file by omitting it from its manifest.
- Suppression checks exact connector Task name/path/XML hash, action, current SID
  and logon-trigger owner; both Run inventories and exact generated command/kind
  are checked before removing only that Task and Run value. Registry-view aliasing
  is handled only after both views passed their preimage gate. Any partial result
  stops and retains a separate read-only postimage; no rollback or retry.
- Stop reacquires the process identity and both locks, then holds the exact
  process handle, checks its start time and terminates only that process (not a
  tree or Codex). It independently verifies exit. Unknown processes or live lock
  owners prevent quiescence. Stale lock files are retained, never cleaned.
- Cutoff capture checks quiescence before and after, exact numeric route filename
  sets before and after, and source identity/hash again across the complete
  capture. Kernel32 handle information binds volume/file identity and metadata
  to the current path. Open streams deny writes/delete during capture. Raw bytes
  and their native hash are retained in private journal payloads; Python verifies
  decoded size/hash, handle/path identity, stable metadata and strict state JSON.

`DeveloperBAdapter` binds the operation sequence to a canonical digest-anchored
new plan, distinct attempt/cutoff IDs, candidate and mechanism heads, and an
external authority verifier. That verifier must validate a NEW canonical OWNER
message against the exact plan digest and expanded suppression/stop/capture scope;
an untrusted plan's authority ID or a true flag is not such a verifier. Inert tests
inject a fixture verifier; no canonical owner-authority bridge is invoked here.

The native backend verifies the module bytes and explicitly pinned PowerShell
executable/hash. A path-only bootstrap validates private external-root ownership,
ACL grants, reparse/overlap constraints and free fixed-volume capacity before any
private plan/output file is written. Child invocations receive fixed script code
and paths in environment values, never interpolated shell text. Raw outputs stay
private; timeout retains PID and stops without automatically killing/retrying the
child. Current gates prevent all those invocations, including read-only capture.

## Boundary/journal enforcement

UTC and QPC samples plus frequency/boot identity are compared across captures.
Observations must be <=120 seconds old; backward/future timestamps, changed clock
source or detectable wall/QPC discontinuity over 50 ms stop the model. This bound
must be reviewed as measurement uncertainty, not called perfect clock provenance.

The journal exclusively creates new attempt/record/head paths. Every immutable
record binds plan, attempt, cutoff, boundary, producer, sequence and prior digest.
A separate disjoint head directory retains each head; callers retain its digest
independently. Raw canonical bytes, complete file sets and every head/chain link
are checked before further actions and boundary capture. Partial, extra, reused,
reparsed or tampered evidence stops. A failed evidence write is reported separately
from native failure. No journal resume, cleanup or repair exists.

After cutoff, `verify_boundary` requires the independently retained head and
collects new native before/after snapshots. Both must remain quiescent, have exact
static identities and unrelated Task/Run content, and the complete raw route set
must match the frozen cutoff byte-for-byte. Even sequence-only movement stops.
It accepts only the six named remove/add/plugin-add boundaries and returns no
installation authority. Boundaries must occur once in exact order; any failed or
out-of-order boundary permanently closes that adapter instance. Raw failed-boundary
evidence is retained before validation when a native capture is available. Actual
marketplace commands are not part of this adapter.

## Precisely unproven platform capability

File writes use fsync and a required native directory-metadata flush barrier;
unsupported barriers stop BEFORE suppression, with the attempt reserved. However,
the Windows directory FlushFileBuffers path has NOT been invoked or established
as a supported crash-durability mechanism. Microsoft documents directory handles
and file flush separately; API presence or a successful ordinary file flush is
not proof of durable directory publication. See [Directory Handles](https://learn.microsoft.com/en-us/windows/win32/fileio/obtaining-a-handle-to-a-directory)
and [FlushFileBuffers](https://learn.microsoft.com/en-us/windows/win32/api/fileapi/nf-fileapi-flushfilebuffers).
The source's directory call is a fail-closed capability gate, NOT a durability
claim. If unavailable, it needs a reviewed supported durability alternative; no
file-only fallback, volume flush, elevation, new service or live experiment is
authorized here. Native ACL/handle/clock/canonical-authority and durability behavior
still need separately authorized isolated Windows validation before any live
packet can claim operational readiness. Hardware power-loss resilience is not
established by these synthetic tests.

## Current validation and limits

Ten inert native-backend tests cover successful preparation/six boundaries,
old IDs/missing authority, partial suppression, unrelated Task drift, PID reuse,
another writer, a live lock despite an empty list, path swap, wrong raw digest,
new route, malformed state, clock jump, missing directory durability, journal
tamper/wrong external head, boundary drift, bad ordering and attempted resume. The existing 33
contract tests remain separate. PowerShell AST and its closed gate are tested
without invoking a native collector. No fresh live backup, suppression, process
stop, install, delivery, replay, activation or other-machine work occurred.

## Review correction #11571

The accepted suppression postimage remains the retained Task/Run/static anchor.
Before-stop observations must preserve it, including continued startup absence,
before stop intent is issued. The native stop helper receives that retained
anchor and checks it against fresh native observations before process handling
and again immediately before signaling. Later preparation observations remain
chained to the accepted anchor; no fresh snapshot resets the preservation baseline.
Regressions inject Task and Run drift at every transition from before-stop through
cutoff-after, retain available failed evidence and enforce terminal no-replay.
Operation timeouts now attach the child PID to both the exception and stopped
result; an inert subprocess timeout fixture verifies no kill, retry or subsequent
capture. These corrections do not open either gate or resolve the native platform
and canonical owner-authority blockers above.
