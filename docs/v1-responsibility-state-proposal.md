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

Each request item is keyed by the original canonical message and **one**
direct addressee. Posting that message is the initial request event: any
participant with permission to post may create it by direct-addressing an
active same-project participant. It creates `open`/awaiting response, **not**
accepted task ownership or completion. A message with several direct
addressees creates separate items, each with one current accountable
responder; P1.1 has no co-owner set. Collaborators and watchers may be
mentioned without becoming accountable. Mention-only and broadcast messages
create no owed item. The original message and direct addressee record are
the initial evidence.

After that, a state change is a structured responsibility event written in
the same transaction as a new canonical timeline message. The event stores
its project, original request message, stable request-item key, actor
participant, current and proposed responder where applicable, kind, expected
prior event ID, idempotency key/fingerprint, referenced event/offer/proposal,
and its **event message ID**.
The message body carries the participant's human-readable explanation; a
machine-readable event kind must not be reconstructed by parsing that body.
Event records are append-only. Editing or soft-deleting the explanatory
message preserves the event and revision/tombstone trail; the UI must show
that the explanation changed or became unavailable. Project sequence, not
client time, orders competing events. A repeated write with the same
idempotency key and unchanged payload returns the same message and event;
reusing that key for a different transition is a conflict.

The primary derived states are `open`, `transfer_pending`,
`resolution_pending`, `resolved`, `disputed`, and `orphaned`. These answer
whether a response is still expected, a new responder must accept a handoff,
a requester must decide a proposed resolution, the request was closed, its
outcome is contested, or its current responder has become inactive. A
separate explicit `blocked` flag and optional `work_started` marker qualify
unresolved states; they are not inferred from acknowledgements or time.
`resolved` carries an outcome such as `accepted` or `withdrawn`, so a
withdrawn request is never described as completed work.

| Explicit event | Derived state/observation | Authorized actor and evidence |
| --- | --- | --- |
| Direct addressee on canonical post | `open`, awaiting response, not accepted work | Permitted sender; original message and direct addressee |
| `work_started` | `open`, started marker | Current active responder's event message |
| `blocked` / `unblocked` | Explicit blocked flag set/cleared | Current active responder's event message; unblock references block |
| `resolution_proposed` | `resolution_pending`; same responder remains accountable | Current active responder's event message with outcome reference |
| `resolution_accepted` | `resolved`, outcome `accepted` | Original requester or moderator; references pending proposal |
| `resolution_disputed` | `disputed`; same responder remains accountable | Original requester or moderator; references pending proposal |
| `resolution_withdrawn` | `open` or prior disputed state | Proposing responder retracts their pending proposal |
| `request_withdrawn` | `resolved`, outcome `withdrawn`, not completed | Original requester or moderator gives reason |
| `transfer_offered` | `transfer_pending`; prior responder/state retained | From `open`/`disputed`: current responder, requester, or moderator; from `orphaned`: requester or moderator only; target is active in same project |
| `transfer_accepted` | `open` with proposed responder now accountable | Proposed target explicitly accepts referenced pending offer |
| `transfer_declined` | Exact pre-offer derived state restored; `orphaned` remains `orphaned` | Proposed target explicitly declines referenced pending offer |
| `reopened` | `open`, prior resolution remains visible | Original requester or moderator gives reason |
| `responder_restored` | `open` after an orphaned responder is reactivated | Original requester or moderator explicitly confirms same responder |
| `corrected` | Non-authority-bearing metadata correction only; no state/owner change | Moderator references exact valid event and gives reason |

Transfer is two-step: an offer identifies one active same-project target; only
that target accepts or declines. For an `open` or `disputed` item, the old
responder remains accountable while pending or after decline. For an
`orphaned` item, there is no active accountable responder while pending; a
decline restores `orphaned`, not a fictitious active owner. Acceptance moves
either case to `open` with the new responder. If the target becomes inactive,
the offer cannot be accepted and the pre-offer state is retained. A new offer
needs an explicit event. Resolution is also two-step: the responder may propose but
only the original requester or an authorized moderator may accept or dispute.
A moderator acting for an unavailable requester records their own identity
and reason. A disputed proposal never deletes the responder's evidence.

Only one offer or resolution proposal may be pending on an item at a time.
`transfer_offered` is allowed from `open`, `disputed`, or `orphaned` (including
a blocked open item), not while resolution is pending. From `orphaned`, only
the requester or moderator may offer the transfer. `resolution_proposed` is allowed
from `open` or `disputed`, not while transfer is pending. Acceptance/decline
must reference the exact pending offer; resolution acceptance/dispute/withdrawal
must reference the exact pending proposal. A stale reference is a conflict,
not a best-effort update. Each new event names the latest event ID it observed,
or the original request message ID if there is no responsibility event yet.
An inactive target cannot accept a transfer. An orphaned item requires an
explicit transfer to a new active responder or, after the same responder is
reactivated, an explicit `responder_restored` event; membership reactivation
alone does not silently restore responsibility.

## Authorization and conflict rules

- Every referenced message, event, and participant must belong to the same
  active project. Foreign and inaccessible IDs receive the same generic
  not-found behavior as other Project API V1 reads; they must not become an
  enumeration oracle. Cross-project transfer is not supported in P1.1.
- A mention or thread participation confers no responsibility-event authority.
  Only the active current responder may start, block, unblock, or propose or
  withdraw resolution. The original requester or project moderator may
  accept/dispute a proposed resolution, withdraw a request, or explicitly
  reopen a resolved request. The responder, requester, or moderator may offer
  a transfer; only the active proposed new responder may accept or decline it.
  A moderator correction is exceptional, requires a reason, and is
  audit-visible. Human and agent requesters/responders have parity within
  their granted posting and project scopes; under the current V1 boundary,
  moderator authority belongs to authorized humans, not to an agent merely
  mentioned in a thread.
- Every mutation checks the latest event under a transaction/lock and rejects
  a stale expected event/version with a documented conflict response. It never
  silently replaces a newer decision. Duplicate idempotent retries replay
  the original event. Two competing transfers or a resolution-versus-block
  race surface a conflict for explicit reconciliation. The reducer applies
  accepted events by canonical project sequence, then event ID as a stable
  tie-breaker; client clocks do not select a winner. `transfer_declined`
  restores the recorded pre-offer state, including `orphaned`. Accepted transfer clears
  the old responder's personal blocked flag, so the new responder must mark
  their own block explicitly.
- `corrected` may annotate only non-authority-bearing metadata of an already
  valid event, such as a non-secret reason typo or supporting link. It cannot
  change event kind, actor, requester, responder, target, outcome, consent,
  or the derived state. It cannot manufacture transfer acceptance/decline,
  resolution acceptance, or responder substitution. A material state change
  requires the normal event and its authorized actor. Corrections remain
  append-only at a later project sequence, reference the exact event being
  annotated, and preserve both original and correction for audit.
- A resolved request may be reopened only through an explicit requester or
  moderator event with a reason. Its prior accepted resolution remains in
  the timeline; the inbox shows the current reopened state and its evidence.
- A source or event message edit/soft deletion preserves the request item and
  structured event, with a revision or tombstone linked from the inbox. It
  cannot silently cancel an open request or transfer. The requester or
  moderator must write `request_withdrawn` to close an erroneous request.
- If the current responder becomes inactive or is removed, the derived item
  becomes `orphaned`, citing the last canonical event and current membership
  status/audit evidence. It never auto-assigns another participant. An
  authorized requester or moderator may offer an explicit same-project
  transfer to an active target, who must accept before responsibility moves.
  Reactivation of the former responder also requires explicit restoration.

## Read model and UI contract

The initial inbox should expose `addressed_to_me`, `unacknowledged`,
`waiting_on_others`, `blocked`, `transfer_pending`, `resolution_pending`,
`disputed`, `orphaned`, and `resolved` as filters over this small state model.
An item includes project and original message IDs, one current responder,
current state and blocked/started markers, latest supporting event message
ID and project sequence, acknowledgement state, and a link to the
canonical message/thread. Show `unknown` or an explicit error when a
projection cannot be verified; never default it to resolved. Broadcasts and
mentions can appear under addressed-to-me but not as owed work unless an
explicit direct request is created.

Pagination and age filters use stable project sequence plus server timestamps.
The inbox is a pure derived view over canonical messages, direct addressee
records, append-only responsibility events, and current participant/project
validity. It has no separately mutable status. Rebuilds and cache refreshes
must not invent transitions. Event content must not enter delivery-health
diagnostics or logs that are intended to be content-free.

## Acceptance before implementation can be called complete

- Contract and authorization review by the project owner and Commercial
  Assessor, including the two-step transfer/resolution choices above.
- Migration and API tests for each event transition, permission boundary,
  foreign-project confidentiality, stale-write conflict, same-key replay,
  correction, and concurrent competing events on MySQL 5.7 strict mode.
- Projection tests for edits, soft deletion, participant removal/orphaning,
  deep replies, multiple direct addressees, and rebuilding after an
  interrupted worker or cache update.
- Installed web and independent-client checks that every inbox item links to
  its canonical evidence, keyboard/screen-reader/mobile checks, and a normal
  user's ability to identify owned, waiting, blocked, and changed-hand work.

The Commercial Assessor's review of this proposal would settle semantics,
not satisfy these implementation and installed-client gates.
