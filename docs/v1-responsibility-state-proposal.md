# Responsibility state and handoff contract (Phase 1 candidate)

**Status:** proposal for owner and Commercial Assessor review. No responsibility
state API, inbox, or installed-client acceptance is claimed by this document.
This builds on the candidate [V1 coordination contract](v1-coordination-contract.md)
without changing what a message acknowledgement means.

## Product boundary

The canonical project timeline remains the system of record. A direct address
identifies an expected responder, a mention attracts attention, and a broadcast
is project-wide visibility; none proves that a task was accepted or completed.
Reading, notification, and acknowledgement never resolve work. The inbox is a
projection of explicit project evidence, not a second editable source of truth.

The first Phase 1 slice should answer four separate questions:

1. What messages were addressed to me, and which have I not acknowledged?
2. Which direct requests are still waiting for an explicit outcome?
3. Which requests are explicitly blocked, and by whom or what?
4. Which requests were handed off, disputed, or resolved, with a link to the
   exact messages that support that state?

Do not infer a resolved or blocked state from an acknowledgement, a reply, a
notification receipt, a timestamp, or an AI-generated summary.

## Proposed evidence model

Each responsibility is keyed by the original canonical request message and
one expected responder participant. A direct addressee creates an initial
`awaiting_response` projection for that responder. A mention or broadcast
does not create an individually owed responsibility. The original message and
its addressee record are the evidence for the initial projection.

After that, a state change is a structured responsibility event written in
the same transaction as a new canonical timeline message. The event stores
its project, original request message, actor participant, affected responder,
kind, prior event/reference where applicable, and its **event message ID**.
The message body carries the participant's human-readable explanation; a
machine-readable event kind must not be reconstructed by parsing that body.
Event records are append-only. Editing or soft-deleting the explanatory
message preserves the event and revision/tombstone trail; the UI must show
that the explanation changed or became unavailable. Project sequence, not
client time, orders competing events. A repeated write with the same
idempotency key returns the same message and event.

| Explicit event | Derived display | Required actor/evidence |
| --- | --- | --- |
| Direct request addressee | Awaiting response | Original canonical message and direct addressee |
| `work_started` | Unresolved/in progress | Current responder's event message |
| `blocked` | Blocked | Current responder's event message with a non-secret reason; optional dependency reference |
| `unblocked` | Unresolved/in progress | Current responder's event message referencing the block |
| `resolution_proposed` | Resolution proposed, awaiting requester confirmation | Current responder's event message and outcome reference |
| `resolution_accepted` | Resolved | Original requester or authorized moderator's event message referencing the proposal |
| `resolution_disputed` | Disputed/unresolved | Original requester or current responder's event message referencing the disputed proposal |
| `transfer_offered` | Transfer pending; current responder still responsible | Current responder or requester offers an active same-project participant |
| `transfer_accepted` | Transferred; new responder now responsible | Proposed new responder's event message referencing the offer |
| `transfer_declined` | Transfer declined; prior responder remains responsible | Proposed new responder's event message referencing the offer |
| `corrected` | Recomputed from explicit correction | Authorized moderator's event message naming the event being corrected and a reason |

Transfer is two-step so one participant cannot silently assign responsibility
to another. If a proposed target is removed or becomes inactive before
acceptance, the offer expires as invalid and the original responder remains
responsible. Resolution is also two-step so the responder cannot unilaterally
erase a requester's unresolved view. A requester who is unavailable may be
substituted only by an authorized project moderator, with the actor and reason
visible on the timeline.

## Authorization and conflict rules

- Every referenced message, event, and participant must belong to the same
  active project. Foreign and inaccessible IDs receive the same generic
  not-found behavior as other Project API V1 reads; they must not become an
  enumeration oracle.
- Only an active current responder may mark their own work started, blocked,
  unblocked, or propose resolution. The original requester or an authorized
  project moderator may accept or dispute a proposed resolution. A new
  responder must explicitly accept or decline an offered transfer.
- The requester may offer a transfer, but the current responder remains
  responsible until the new responder accepts. Moderator correction is
  exceptional, requires a reason, and is audit-visible.
- Every mutation checks the latest event under a transaction/lock and rejects
  a stale expected event/version with a documented conflict response. It never
  silently replaces a newer decision. Duplicate idempotent retries replay
  the original event. Two competing transfers or a resolution-versus-block
  race surface a conflict for explicit reconciliation.
- Corrections do not delete history. A correction references the precise
  event being superseded and the replacement interpretation. Both remain
  readable to authorized project participants.
- A resolved request may be reopened only through an explicit requester or
  moderator event with a reason. Its prior accepted resolution remains in
  the timeline; the inbox shows the current reopened state and its evidence.

## Read model and UI contract

The initial inbox should expose `addressed_to_me`, `unacknowledged`,
`waiting_on_others`, `in_progress`, `blocked`, `transfer_pending`, `disputed`,
and `resolved` as distinct filters. An item includes project and original
message IDs, current responder, current state, latest supporting event
message ID and project sequence, acknowledgement state, and a link to the
canonical message/thread. Show `unknown` or an explicit error when a
projection cannot be verified; never default it to resolved. Broadcasts and
mentions can appear under addressed-to-me but not as owed work unless an
explicit direct request is created.

Pagination and age filters use stable project sequence plus server timestamps.
The projection must be reproducible from canonical messages, addressee
records, and responsibility events. Rebuilds and cache refreshes must not
invent transitions. Event content must not enter delivery-health diagnostics
or logs that are intended to be content-free.

## Acceptance before implementation can be called complete

- Contract and authorization review by the project owner and Commercial
  Assessor, including the two-step transfer/resolution choices above.
- Migration and API tests for each event transition, permission boundary,
  foreign-project confidentiality, stale-write conflict, same-key replay,
  correction, and concurrent competing events on MySQL 5.7 strict mode.
- Projection tests for edits, soft deletion, participant removal, deep replies,
  and rebuilding after an interrupted worker or cache update.
- Installed web and independent-client checks that every inbox item links to
  its canonical evidence, keyboard/screen-reader/mobile checks, and a normal
  user's ability to identify owned, waiting, blocked, and changed-hand work.

The Commercial Assessor's review of this proposal would settle semantics,
not satisfy these implementation and installed-client gates.
