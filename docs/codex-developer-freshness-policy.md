# Proposed Developer-host freshness and cutover policy

SOURCE/PREPARATION ONLY. Requires separate Commercial review and new exact
execution authority. It does not amend or resume the stopped attempt under 11545.
Owner 11522 remains controlling; no other machine or hidden-cloud investigation
is part of this policy.

## Retain the failed attempt

Keep packet 11543 and attempt 11545 immutable. Its zero-command preflight STOP
in 11546 stands. Never replace that packet's state files, Task XML, hashes or
result, and never reuse its attempt identifier/approval. Any future packet links
to the failed evidence and creates separate immutable observations and backups.

Read-only follow-up compared the nine changed state files with the original
recovery copies. Seven differed only in `lastSequence`; one also differed in
`pendingNotifications`; one also differed in `activeWake` and
`processedMessageIds`. These are later observations, not reconstructed bytes
from the original preflight instant. They do not establish the writer or cause.
The unrelated Task changed Interval/StartBoundary; the exact connector Task did
not change. No Task difference is normalized or declared harmless by this policy.

## Distinguish intent from moving observations

The reviewed immutable installation intent remains exact: CLI hash/version,
candidate commit and bytes, target marketplace identity, relevant configuration,
installed/cache/runtime code, protected credentials/binding identities, inactive
backup artifact, exact connector Task/Run/process identity and lock ownership.
Changes to these remain STOP conditions; no automatic baseline refresh.

Route-state bytes and unrelated Task XML are time-bound observations, not fixed
installation intent. This distinction permits proposing a NEW cutoff packet; it
does not permit substituting fresh observations into the failed approved packet.
The new packet must explicitly authorize its capture boundary and permitted
comparisons. Preserve current queue/history rather than restore an old snapshot.

## Two explicit preparation paths; neither selected or authorized here

1. **Install-only with an active listener.** A newly reviewed packet may define a
   fresh private cutoff immediately before the first native mutation. Capture
   every canonical state file and its raw digest with source-handle/path identity
   and before/after stability checks; verify the copy, schema and unchanged route
   set. Retain fresh unrelated Task XML and all unchanged static preimages. Give
   the cutoff an explicit age bound of at most 120 seconds and require another
   separately retained observation at each command boundary. Expired, future,
   unstable or incomplete observations STOP; do not recapture in a retry loop.
   Sequence-only advancement can be classified narrowly for review, but is NOT
   automatically accepted. Pending/history/wake changes require independent
   event evidence or STOP; a nondecreasing sequence alone proves neither queue
   preservation nor no replay. Any unrelated Task change during the actual
   command window remains STOP unless separately attributed and reviewed.
2. **Explicit quiescent cutover.** If the active-listener path cannot provide a
   consistent snapshot, propose a separately approved connector-only suppression
   and exact owned-listener stop BEFORE capturing fresh route-state bytes. This
   expands the earlier install-only boundary and therefore needs explicit new
   authority. Suppress only reviewed connector Task/Run objects, validate process
   start/executable/entry and both locks, stop only that process, independently
   verify quiescence, then take a new verified recovery snapshot. Never alter
   Realtime Outbox, credentials, server settings, queues or unrelated tasks.
   Failed suppression/stop or any unexpected writer leaves evidence retained and
   stops the attempt. Do not clean locks, repair state, force a restart or reopen
   Codex. New installation and later activation boundaries must be named in the
   same reviewed packet; stopping the listener does not authorize activation.

The existing nine changes cannot all be covered by a sequence-only exception.
No proposal should quietly accept pending/history changes to make the check pass.

## Concrete path recommended for the next review

Recommend path 2 (quiescent cutover), not a broader exception for path 1. This is
a recommendation for separate authority, not selection by this document. The
new approval must explicitly name connector Task/Run suppression and stopping
the exact owned listener; the exhausted install-only approval cannot cover them.

The source fixture model orders authority/reservation, exact static identity and
ownership checks, pre-suppression evidence, connector-only suppression and
preservation checks, listener identity revalidation, exact stop, independent
quiescence, new cutoff capture/copy verification, bounded freshness/static checks,
and retained prepared result. Any failure stops before later phases. There is no
native adapter or CLI entry point; no preparation model is run on this machine.

The post-suppression cutoff retains all CURRENT valid canonical route-state
bytes, backup artifact and unrelated Task XML. It does not restore old queues,
assert prior advances were legitimate, mask schedule values, or amend 11546.
During subsequent installation, compare raw bytes and complete identity sets at
every boundary. Even lastSequence-only change is a STOP after quiescence; no
semantic normalization is allowed. The same applies to unrelated Task rescheduling.
Any new writer, changed Task or expired cutoff stops without recapture in place.

The preparation approval and later exact install/activation boundaries must be
specified together to avoid implying a restart is authorized. A preparation
result alone authorizes neither installation nor reopening Codex. Record the
notification interruption and pending-queue preservation explicitly in the new
approval request; do not claim current routing remains active while stopped.

## Preservation checks for a new reviewed window

Unrelated Task baseline selection occurs at the explicitly approved new cutoff,
not by overwriting the old evidence. Keep full raw XML and digests. Compare all
Task identities/configuration throughout the actual installation window; changed
schedule fields are still configuration changes. No global date/interval masking.
If an independent scheduler event establishes another actor's change, retain that
evidence and request a narrow decision; do not claim the installer caused or
preserved it solely from temporal correlation.

Static filesystem/cache/config checks remain exact and separately revalidated.
Use the reviewed native cloud-layer pre-mutation guard; do not reopen cloud
reverse engineering. Every command still has one attempt, independent postimage
checks and STOP on partial/uncertain outcome. The observer and authorized test IDs
remain prerequisites only for final lifecycle acceptance, not packet preparation.

`codex_developer_freshness.py` supplies pure observation classifiers and freshness
bounds only. It neither captures native evidence nor selects a path, authorizes
sequence exceptions, updates baselines, suppresses processes or performs installs.
