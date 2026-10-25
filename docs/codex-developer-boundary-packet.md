# New Developer-host packet scaffold and boundary evidence

Source-only deliverable for 11555. This is not an attempt, captured cutoff, new
backup or execution request. The JSON scaffold proposes distinct IDs, leaves
authority and policy unselected, and binds future work to the immutable failed
11545 evidence. Old files/manifests are never refreshed in place.

## Feasibility decision for review

| Policy | Evidence required | Feasibility on observed traffic | Additional authority |
| --- | --- | --- | --- |
| A: active listener, install-only | Fresh raw state and Task captures at every boundary; independent binding identity and exact queue/history/wake preservation; sequence-only comparison only under the NEW reviewed rule | Fragile: seven later observations were sequence-only, but two also changed queue/wake/history, and unrelated Task scheduling changed. Those changes are not covered by a sequence exception. Without independent attribution, STOP | New exact install packet and authority; explicitly approve its fresh-cutoff/sequence-only rule. No suppression/stop authority implied |
| B: quiescent cutover | Exact owned connector suppression/stop and independently proven quiescence; new verified recovery cutoff; exact frozen state/Task bytes during installation | Recommended for feasibility because it removes the known connector writer. It does not prevent another writer or OS Task rescheduling; either still stops the window | Entirely NEW owner authority for connector Task/Run suppression, exact owned-listener stop and temporary notification interruption, plus separately named install and activation boundaries |

Recommendation does not choose B. Commercial must review the policy/adapter
first; the owner must explicitly grant expanded B authority. A green source test
does not authorize either path. Observer/test-message IDs gate final lifecycle
acceptance only, not preparation of this scaffold.

## Required validation at EACH command boundary

For before/after remove, before/after add and before/after explicit plugin add:

1. Capture through separate native state-file and Task Scheduler collectors.
   Retain raw bytes privately in new exclusive files, with complete opaque route
   and Task identity sets. No application-level parsed/reserialized replacement
   may substitute for the raw evidence.
2. Bind each file read to independently captured volume/file identity from the
   opened handle and current path. Check size/mtime and exact raw hash before
   and after capture/copy; reject link/reparse substitution, path/handle mismatch,
   missing/extra identities, unstable read, malformed JSON or capture failure.
3. Bind captures to the NEW attempt/cutoff IDs, exact plan digest, boundary and
   observation time. At the decision point each observation must be no more than
   120 seconds old. A future timestamp, clock rollback/discontinuity, mixed
   capture, unknown time source or expired observation stops. The cutoff baseline
   itself is never replaced: later fresh observations compare against its bytes
   or the expressly reviewed A rule, not a silently refreshed baseline.
4. Append intent, independent capture references, hashes and confirmed result to
   exclusive journal records chained to the prior record and exact plan. Retain
   the head digest independently outside the journal tree. Verify raw canonical
   record bytes, ordered sequence, previous hash, producer identities, complete
   record/head sets, plan/attempt/boundary bindings and freshness before consuming
   evidence. A hash supplied by the same unverified caller is not independent
   provenance. Never resume a partial journal or overwrite a prior record.
5. Check immutable install identities and the exact expected filesystem/config
   postimage for that stage separately from route/Task observations. The native
   cloud pre-mutation guard remains the accepted mechanism; no further hidden
   cloud searching is required. A guard rejection or uncertain result stops.
6. For A, compare all queue/history/wake fields exactly and require a separately
   retained identity/queue proof anchored to the trusted journal. Sequence-only
   movement without that proof stops. Pending/history/wake changes stop even if
   sequence increased or a caller claims harmlessness. Task Interval/StartBoundary
   changes stop; no global timestamp/schedule masks. For B, all route and Task
   bytes must remain exact after the quiescent cutoff, including lastSequence.

The pure A consumer is a synthetic model only. It checks an externally supplied
record digest, plan/attempt/cutoff/prior-head bindings, age, independent identity
and queue digest consistency and exact Task bytes. It does not provide native
handle/file capture, prove the evidence collector's authenticity, establish clock
provenance or flush a Windows journal. The future native evidence adapter and its
durability/provenance integration remain unavailable; no operational readiness is
claimed. Synthetic passing results always carry `authorizes_mutation=false`.

## Exact next decisions

Review this source rule and scaffold at its exact PR head. Decide whether A has
sufficient independently obtainable evidence or whether to prepare B's expanded
owner request. Do not ask the owner to approve a live attempt until the selected
native adapter, exact new packet, preservation boundaries and authority are
concrete. Do not reuse 11545 or prepare an implicit retry. No live backup,
suppression, process action, install, delivery, replay, cleanup or service start
is authorized by this document.
