# Developer-only integrated procedure - CLOSED, NOT execution authority

This is the single proposed sequence for resolving the original installation,
session lifecycle and routed-delivery issue. It composes the preparation state
machine, persistent-session protocol, protected-host receipt contract and separate
fixed-file head ledger. It is not a runnable installation script. Native entry
points still refuse unconditionally. No new owner test or live collection is
requested by this source review. The installed version last reported was 0.2.7;
0.2.10 remains uninstalled and unverified. Other machines remain excluded.

## Immutable candidate and retained history

- Candidate source: `9141daa08d896f2524be30b96d01ab2a14398757`, version 0.2.10,
  48 files, 375905 archive bytes; ZIP SHA-256
  `331e47a6ee3cd690846ac7f2d302566658e4be195c3a734228f457f55e61feeb`.
- The mechanism revision is the exact reviewed PR head, separately pinned from
  the plugin candidate. Require its complete CI outcome, not an ancestor's pass.
- Attempt 11545 and its approval are exhausted. All isolated native-test approvals
  are consumed. Preserve every old tree, manifest, raw preimage, result and hash;
  no cleanup, reconstruction, renaming, new baseline or automatic replay.
- Preserve #11582's independently retained postimage/Task/state anchors. A source
  review or receipt of this document does not authorize any host action.

## Ordered phases and required evidence

1. **Source review and host bridge admission.** Review this exact sequence plus
   `codex_developer_integrated_preparation.py`, `codex_developer_transport.py` and
   the PowerShell fixed-session protocol/worker. Resolve the blockers below before
   any gate can be considered for a separately reviewed change. No gate flag or
   executable override is accepted from a plan, message body or environment.
2. **Separately authorize fresh read-only admission.** A later explicit owner
   request must name the Developer host, exact mechanism/candidate, immutable new
   attempt/cutoff, evidence bounds and authorized observations. Only then obtain
   current installed provenance/inventory, CLI/runtime hashes, Task XML and Run
   values, owned listener PID/start/executable/command, lock witnesses and complete
   route-state raw bytes. Account for the excluded unrelated Task and malformed
   historical file without deleting or repairing them. Drift stops; never update
   an approved preimage silently. No live collection occurs in this source phase.
3. **Separately admit backup and existing containers.** Establish an externally
   verified backup outside webroot and an independently owned caller ledger under
   a separately authorized bounded setup. Pin full normalized paths, file IDs,
   lengths, private owner/SYSTEM ACLs, namespace, slot count/width and runtime.
   The writer's pair and the trusted caller's pair must be distinct, non-overlapping
   identities. Both full manifests are bound to the native plan context. Determine
   capacity against complete observations plus worst-case multipart records,
   authority receipts, heads, failure records and all six boundaries. No reuse of
   occupied/partially written containers; no create/resize/rename in preparation.
4. **Obtain fresh scoped preparation authority.** The request's canonical JSON
   binds the reviewed native context, fixed-plan digest, candidate, mechanism,
   manifests, attempt/cutoff, interruption, exact suppress/stop/capture scopes and
   time window. The owner approval event ID is checked separately after approval;
   it is excluded only from the context digest to avoid self-reference. Retrieve
   both messages via the exact protected profile and validate canonical owner,
   request/event linkage, revision/deletion and all bindings. A plain copied JSON
   response or caller flag is not authenticated authority. Changed/unavailable or
   expired authority stops without replay. An installation approval remains separate.
5. **One preparation attempt.** Open one continuously held existing writer pair and
   one separate trusted-caller ledger pair. Retain the canonical receipt and
   compare-and-advance head in the caller ledger, flushing and reading back both
   record/head slots. Retain raw observations before intents; compare all prior
   head history before advancement. Suppress only the exact reviewed connector
   Task/Run entries, preserve the unrelated entries and retain the postimage.
   Revalidate against that postimage, stop only the exact owned listener, obtain
   independent quiescence, then capture cutoff with raw handle/path witnesses.
   Recheck retained observation age after all slow writes/authority work immediately
   before every action; publication cannot return success with evidence over 120s.
   Never recapture to bypass age failure. STOP retains evidence and closes sessions
   once. An uncertain close is reported separately without retry. No installation
   authority is produced by successful preparation.
6. **Separately authorize and execute bounded installation.** After admission and
   preparation review, a distinct owner authority must explicitly permit the exact
   CLI sequence reviewed in `codex-developer-cli-source-review.md`: marketplace
   remove `syndicatum`; Git marketplace add from the exact candidate commit with
   only `.agents/plugins` and `plugins/codex` sparse paths; explicit plugin add
   `codex@syndicatum`. No `main` ref, newest tag, HomeServer payload or cache
   substitution. Each remove/add/plugin-add command requires its named before/after
   frozen boundary. Verify Task XML, Run values, static files and raw cutoff states
   at all six boundaries against the preserved anchors. No retries on timeout,
   EOF, unknown result or partial outcome. The current adapter only verifies those
   boundaries; the actual installer/activation command dispatcher remains missing
   and must be reviewed before execution. After the sixth boundary, the adapter
   closes both sessions itself and checks freshness again before success.
7. **Independent installed verification and separately authorized activation.**
   Verify exact installed source revision, version, all expected files/hashes,
   managed source/cache identity, configuration/bindings/queue/history preservation
   and absence of unrelated mutations. Failure retains evidence and halts; rollback
   is not automatically authorized. Only a distinct activation/restart permission
   permits starting the new lifecycle. Never force Codex to reopen after the owner
   intentionally closes it.
8. **Observed lifecycle and delivery acceptance.** Observe normal user opening and
   closing Codex: the listener starts with the intended session and stops when the
   app is deliberately closed. Confirm an authorized routed notification reaches
   the exact discussion/profile, is handled once, and one route's failure does not
   block another. Observe subsequent reopen/recovery without resending an uncertain
   mutation. Record actual installed revision and bounded non-secret evidence in
   Syndicatum. Only this acceptance resolves the working-installation objective.

## Implemented host mediation and closed process source (#11653)

No special server export or global remote CAS is required for this cooperating
one-host scope. The trusted Codex agent CAN invoke the existing protected tool.
The earlier statement treating Python's lack of MCP dispatch as a new platform
prerequisite was too broad. Source implementation now consists of:

- `codex_developer_agent_host.mjs`: the trusted agent supplies its existing `tools`
  object and private local delivery callback. The code directly invokes the exact
  protected tool with the exact profile/message ID, checks canonical response
  identity, retains original UTF-8 result text as Base64 and correlates one-use
  nonce, session, sequence, request, approval and reviewed context. Wall/monotonic
  deadline checks prevent a late read-only result from starting delivery. A timed
  out or uncertain delivery is terminal and never retried.
- `TrustedAgentChannel`: accepts that trusted callback's one-use result, not an
  arbitrary plan file. It compares the full invocation, bounds raw bytes to32KiB,
  writes exact bytes/digests/host timestamps/context into a separately pinned
  private existing receipt pair, flushes/read-backs and verifies prior history.
  It rechecks freshness after publication before delivery to CanonicalAuthority.
  That parser still validates owner, positive event, actor, request/body/context,
  deletion and revision. The native plan pins the full receipt manifest and
  disjoint writer/caller/receipt identities. No cryptographic attestation is claimed.
- Within physical multipart writes, authority checks may reuse the same validated
  host snapshot ONLY within its120s bound. Every completed logical publication and
  every immediate action admission explicitly invalidates that cache and obtains
  new host-mediated request/approval results. This avoids hundreds of redundant
  tool round trips without substituting a snapshot for final action admission.
  An expired observation still stops; authority refresh cannot rebaseline it.
- `PinnedWindowsProcess`: implements the single-child launch/pipe path behind an
  unconditional launch refusal. The exact executable, worker, protocol and storage
  module must match the reviewed hash set. Launch uses explicit executable/argv,
  no shell/profile, hidden window, closed inherited handles and private stdio
  pipes. Owned child PID/creation FILETIME/image/hash is checked before/after each
  request. Input/output frames are bounded to2MiB with strict correlation, UTF-8,
  duplicate-key rejection and deadlines. stderr is bounded to64KiB. EOF, timeout,
  changed identity, oversized/malformed/late replies stop terminally. Close waits
  once for the owned worker's successful exit; uncertainty never kills/restarts/
  reconnects it or retries a mutation. A blocked daemon IO operation is treated as
  an uncertain child, not cancellation proof or a successful close.
- The PowerShell worker now uses bounded byte/async stream framing with deadlines,
  not ReadLine. Its first statement STILL throws before imports/IO/native calls.
  Python process launch likewise STILL refuses. Tests attach inert child streams
  without patching either gate; no worker or native harness is launched.

The trusted host invocation callback and delivery endpoint are capabilities owned
by the cooperating Codex agent. They must not be selected or supplied by a message
body, plan or public file. The agent can use its existing protected tool dispatch
and private local session/file operations to supply them; no new platform service
is a prerequisite. The persistent ledger supplies local exclusive serialization,
not a global CAS. Live ownership, pipe inheritance and ACL/provenance must be
admitted and observed on the actual host before trusting that wiring.

## Remaining review/admission and practical next step

The installed protected tool exposes `syndicatum_get_message(profile_id,
message_id)` and returns the canonical profile/message shape. It does NOT expose
an authenticated receipt export or global head-CAS operation; neither is required
for the host-mediated scope above. The no-host default still refuses. Inert tests
prove framing/parsing/retention and actor checks, not actual protected-tool origin,
private file ownership or process launch. No raw HTTP/token fallback is permitted.

The Windows worker defines one process/one held pair and uses the validated
storage module's private session functions. Its first statement unconditionally
throws. `WindowsSessionAPI` uses the same correlated protocol in inert tests;
production process launch is implemented but unconditionally closed. Its Windows
PID/image/FILETIME and async-pipe behavior remain unexecuted/unvalidated. Review the
exact runtime/file pins, pipe ownership, stream timeout behavior and child-exit
evidence before any live admission. No claim of real API or hardware durability
follows from an inert stream test.

The separate head ledger now persists and verifies the entire retained sequence
and normalized authority receipt through its own fixed session. The native plan
pins its full manifest. Its actual independent host ownership and trusted durable
provenance are not proved by in-process inert fixtures. Real capacity/performance,
fresh host preimages, backup admission, execution authorities and the installation/
activation dispatcher remain live admission gaps. Physical power-loss durability
is not established by file flush/readback or the earlier small synthetic test.

Next executable admission step, AFTER complete source review and explicit owner
authority for one bounded Developer-only pilot: the trusted agent obtains the
fresh exact-profile request/approval results, privately stages/verifies the pinned
receipt delivery and container/backup manifests, checks the current installed
revision/preimages and measured capacity/time budget, and returns a read-only
admission result for the single procedure above. Do not open execution gates merely
to perform that review. A single pilot authorization can name its conditional
read-only admission and preparation/install/activation scopes explicitly; separate
named permissions do not imply a human round trip for each implementation detail.
If any preimage/authority/pin/ownership/capacity/time check fails, retain that attempt
and stop before mutation. The installation command dispatcher and observed
lifecycle/delivery acceptance still require completion/review under that same
procedure. This source delivery does not request a new owner queue.

These are explicit blocks on live execution. No native harness, live retrieval, storage setup,
suppression, stop, install, activation, merge or other-machine action is authorized.
