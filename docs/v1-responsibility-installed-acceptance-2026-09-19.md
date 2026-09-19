# Responsibility Inbox installed-client acceptance probe — 2026-09-19

This is a bounded local acceptance probe for draft PR #6 at commit
`7f69fda2b5bea47335baac20b07771a8ed28fa02`. It is **not** a V1 release
certificate or full P1.1/P1.2 acceptance.

## Isolated installation

- Built the committed source into Docker image
  `syndicatum-acceptance-ui-f005f23a43ed-app:acceptance` using the release
  Dockerfile, after archiving the exact Git commit to avoid unrelated local
  working-tree content.
- Started Compose project `syndicatum-acceptance-ui-f005f23a43ed` with its own
  database volume and local-only web port `127.0.0.1:18082`.
- `/api/v1/health.php` reported core `ok`, database connected, and expanded
  schema available. The app entrypoint migration completed.
- Registered a disposable owner and created a disposable project and agent
  participant through the installed browser UI. No production accounts,
  projects, or databases were used.

## Observed browser flows

1. The owner sent one direct timeline request to the agent. The native Helper
   timeline showed the canonical message once, with the agent marked as the
   expected responder.
2. The owner’s Responsibility Inbox displayed no item under `My work` and one
   item under `Waiting on others`, naming the correct request and responder.
3. `View original message` focused the mounted canonical row in the existing
   timeline. With a deliberately nonmatching timeline search, the same link
   opened the read-only canonical evidence dialog instead: exact message ID,
   project sequence, sender, time, addressing, revision count, thread context,
   and original body were visible. `Open timeline (filters unchanged)` returned
   to the timeline with the nonmatching search still intact.
4. The owner withdrew the synthetic request with an evidence note. The inbox
   immediately showed no matching waiting item and reported that the action
   was recorded. The existing timeline then contained exactly the original
   direct request and one new withdrawal-note message.
5. At a `390 × 844` browser viewport, `All direct work` showed the withdrawn
   request as `Resolved` with the explicit qualifier `Request withdrawn, not
   completed` and both evidence links. The document and viewport widths were
   both 390 CSS pixels, so the inbox card introduced no horizontal overflow.
   This is a single visual smoke check, not mobile usability acceptance.
6. A second synthetic human registered in a separate authenticated browser
   session. The owner created an invitation through the UI; the second human
   accepted it through the authenticated project-invitations API (the current
   client exposes invitation creation but no acceptance form). The owner then
   addressed a new direct request to that human. The human's `My work` showed
   it once with an `Acknowledge` action.
7. Acknowledgment did **not** mark the request complete. The responder could
   start work with an evidence note, then mark it blocked; each state-changing
   action added one timeline message. `Blocked` found the request. The responder
   proposed resolution and the owner's `Decisions needed` view found it as
   `resolution pending`. Owner acceptance moved it out of `Decisions needed`
   and into `Resolved`. The withdrawn request was separately still labeled
   `Request withdrawn, not completed`.

The installed UI revealed one wording defect: an accepted resolution that had
previously been blocked still displayed `Blocked` beside its resolved badge.
The source client now renders `Previously blocked` for this retained historical
flag, with a unit test. That wording refinement has **not** been rechecked in
an installed image yet.

## Exact-head reload, linkage, and same-key retry probe

A separate isolated Docker install used the Git archive of exact PR #6 head
`5e6ea70a0161fcbd27a8b0da1f0d08f74990b6fa`, image
`syndicatum-acceptance-next-5e6ea70-app:acceptance`, Compose project
`syndicatum-acceptance-next-5e6ea70`, and local-only port `127.0.0.1:18083`.
Health reported core `ok`, database connected, and expanded schema available.
The source/CI jobs for this head passed in GitHub Actions run `35390835429`.
This was new synthetic data, not a continuation of the earlier install.

1. Through the installed browser UI, a synthetic owner created a project and
   agent, then sent one direct request to that agent. The owner’s `Waiting on
   others` view reconstructed exactly one open item, `Request #1`, linked to
   the canonical request message `#1`.
2. The owner withdrew it through the Inbox with an evidence note. The waiting
   view became empty; `Resolved` showed the same `Request #1` with `Request
   withdrawn, not completed` and links to both original and latest evidence.
3. After a full browser reload and reopening the Inbox, `Resolved` still
   contained exactly that one withdrawn request. The server’s resolved view
   showed `request_message_id=1`, `state=resolved`, `outcome=withdrawn`, and
   `latest_evidence_message_id=2` (project sequence 2). The timeline showed
   the original direct request and one withdrawal evidence message.
4. Retrying the exact withdrawal POST with unchanged content and the original
   idempotency key returned HTTP 200, `idempotent_replay=true`, and the same
   canonical message `#2` at project sequence 2. A subsequent timeline query
   still returned exactly two messages, with no duplicate withdrawal event.
5. The owner reopened that resolved item through the installed Inbox with a
   note. `Resolved` became empty and `Waiting on others` showed the same
   `Request #1` as open, with latest evidence `#3` at project sequence 3.
   Reopening did not create another request item.
6. An authenticated API write against this installed stack deliberately used
   stale `expected_event_id=2` after the reopen. It returned HTTP 409 with
   `RESPONSIBILITY_CONFLICT` and an instruction to reload the latest event.
   A fresh Inbox read still showed one open request, latest evidence `#3`;
   the rejected stale action did not advance the projection. This is server
   conflict behavior only, not a browser UX acceptance test.

This confirms only the tested owner-side reload/linkage and same-key replay
path. It does not establish browser stale-conflict recovery UX, responder-side
dispute/transfer, pagination/history, or accessibility acceptance.

## Two-browser stale-conflict recovery probe

A third disposable Compose stack, `syndicatum-acceptance-browser409-5e6ea70`,
served a local-only app at `127.0.0.1:18084` with its own MySQL volume. Two
independently authenticated browser contexts signed in as the same synthetic
owner. Both loaded the same open direct request into `Waiting on others`.

With the exact `5e6ea70` app image, browser B withdrew the request while A
retained its stale card. A's attempted withdrawal received HTTP 409 and
refetched the waiting view, which became empty. The browser initially showed
`Responsibility changed before your action. Review the refreshed item; nothing
was posted by this attempt.` Routine live polling then replaced that specific
notice with a generic activity warning. The canonical timeline still held
only the request and B's withdrawal; A's attempt posted nothing. This exposed
a browser feedback defect, not a server state or duplication defect.

The client fix at exact Git commit `d9ccd631e87514e6f4c7221b96c438e47dee477a`
keeps the conflict notice through live polling until an explicit refresh or
view change. It also distinguishes a successful conflict refresh from a failed
one, so it cannot claim that the newer item was displayed if the fetch failed.
The committed Git archive was built as image
`syndicatum-acceptance-browser409-d9ccd63-app:acceptance` (image ID
`sha256:8d73fd382b492e1069dc0bd9fb2394e131ef567ff8fa3cce0efa8ab950c45019`).
Only the app container was recreated with that image; the disposable database
and its prior canonical events were retained for the replay.

In the revised installed build, B reopened the same request and then withdrew
it again while A retained the newly stale open card. A's action received HTTP
409, and its waiting list refreshed to empty. The specific `nothing was posted`
notice remained visible through subsequent polling; on an explicit switch to
`Resolved`, the same request appeared as withdrawn. A read of the canonical
API showed one responsibility item (`request_message_id=1`, `state=resolved`,
`outcome=withdrawn`, latest evidence message `#5` at project sequence 4) and
exactly four canonical messages at sequences 1–4. The rejected stale writes
created no canonical message or responsibility event. This is a bounded
owner-side browser recovery result, not a full multi-role or accessibility
acceptance claim.

## Resolved historical-label installation probe

A fourth disposable Compose stack,
`syndicatum-acceptance-wording-d9ccd63`, served the exact previously built
`d9ccd63` application image at `127.0.0.1:18085` with a verified MySQL 5.7.44
image and fresh isolated volumes. Health reported core `ok`, database
connected, and expanded schema available. The application image is the same
`sha256:8d73fd382b492e1069dc0bd9fb2394e131ef567ff8fa3cce0efa8ab950c45019`
used for the revised 409 probe; the later PR commits changed evidence and
checklist documentation, not this runtime.

Two synthetic human accounts were registered through separately authenticated
installed browser sessions. A project and an unclaimed agent were created only
as disposable fixtures; no agent claim code or protected task profile was used.
The owner invited the second human, who accepted through the installed,
session/CSRF-protected invitation API because the current browser client has no
invitation-acceptance form. The owner posted a direct request to the responder
through the installed authenticated API; the responder posted `work_started`,
`blocked`, and `resolution_proposed` evidence; the owner accepted the proposal.
Each write returned HTTP 201. The canonical timeline then contained exactly
five messages with sequences 1–5.

The real installed owner browser selected the Responsibility Inbox's `Resolved`
view. It rendered one `Request #1` card as `Resolved` with the labels
`Previously blocked · Work started`, not a contradictory current `Blocked`
label. After a full page reload and reopening that view, the same card and
labels reconstructed. An authenticated inbox read returned one resolved item
with `blocked=true`, `work_started=true`, and latest evidence message `#5`.
This verifies only the installed historical wording for an accepted resolution
that retained a blocked-history flag. It does not verify other historical
baselines, screen-reader presentation, or full P1.1/P1.2 acceptance.

## Transfer and orphan-recovery probe

A fifth disposable Compose stack first exercised the installed `d9ccd63`
image with three independently authenticated synthetic humans. A responder
offered a direct request to a second responder, who declined once and then
accepted a second offer. Removing the new responder correctly placed the
request in the owner's `Unassigned` view with `No active owner`. The owner
then offered that orphaned request back to the still-active former responder,
who accepted it. The acceptance event was canonical, but the installed inbox
still projected the request as orphaned and omitted it from the recipient's
`My work` view.

The defect was in `ResponsibilityEventService`: transition validation used the
pure reducer's return value only for validation and discarded the resulting
state before choosing the responder status-generation anchor. A normal
generation-zero transfer masked the error; orphan recovery across responders
with different status generations exposed it. Commit `771a222` retains the
reduced state and anchors an accepted transfer to the newly selected responder.
A focused MySQL integration regression recreates that asymmetric-generation
case and passed with the broader responsibility persistence/concurrency suite.

The exact `771a222` worktree was built as
`syndicatum-acceptance-771a222-app:acceptance` (image ID
`sha256:43fa66719429fa56cce232fac25e39585af8ebd994733555c6f8592f6cd28443`).
A fresh isolated stack served that image at `127.0.0.1:18087` with MySQL
5.7.44; health reported core `ok`, database connected, and expanded schema
available. In the installed browser, the initial responder offered request
`#1` to the target and the target accepted it into `My work`. Removing that
target placed the same request in `Unassigned` as `Orphaned` with `No active
owner`. The owner offered it to the active former responder. After that
responder accepted through the installed browser, `My work` showed exactly one
open `Request #1` owned by `Fix Responder`, with its latest canonical evidence
link. This closes only the exercised offer/decline/accept, deactivation orphan,
and owner-mediated orphan-recovery paths; it is not dispute/reopen,
accessibility, or complete P1.1/P1.2 acceptance.

## Dispute, revised resolution, and reopen probe

A sixth disposable Compose stack served the same exact `771a222` application
image and MySQL 5.7.44 at `127.0.0.1:18088`, with fresh isolated volumes and
two independently authenticated synthetic humans. The owner created one
direct request for the responder. In the installed Responsibility Inbox, the
responder proposed a resolution and the owner saw it in `Decisions needed`.
The owner disputed it with an explicit evidence note; the same request then
appeared in the owner's `Disputed` view and remained assigned to the responder.
The responder's `My work` view also showed the disputed item and allowed a
revised resolution proposal.

The owner accepted the revised proposal, observed the request once in
`Resolved`, and then used the installed `Reopen` action with a reason. The
request returned once to the owner's `Waiting on others` view as `Open`, still
assigned to the original responder. An authenticated projection read confirmed
one item with `state=open`, `current_responder_participant_id=2`, no pending
decision, and latest evidence message `#6` at sequence 6. The canonical message
list contained exactly sequences 1–6: request, first proposal, dispute, revised
proposal, acceptance, and reopen. This closes only that installed
dispute/re-proposal/accept/reopen path; other role variants, pagination/history,
edit/tombstone, responsive, accessibility, and overall P1.1/P1.2 remain open.

## Pagination, history, and unknown-baseline probe

A seventh disposable Compose stack served the same exact `771a222` application
image and MySQL 5.7.44 at `127.0.0.1:18089`, with fresh isolated volumes and
two independently authenticated synthetic humans. The owner created 55 direct
requests for the responder through the installed session/CSRF-protected API.
The oldest request's responsibility generation anchor was then set to null in
the isolated database to reproduce the documented historical direct-message
condition without inventing a current assignment.

The authenticated installed API returned the `All direct work` data as 50 + 5
items, with `has_more` true only on the first page and 55 unique request IDs
across both cursors. The oldest item was explicitly projected as `unknown`,
with no current or last responder, no latest responsibility evidence, and
`projection_error=RESPONSIBILITY_BASELINE_UNAVAILABLE`.

The real installed owner browser showed 50 cards and `Load older work`. After
loading the second page it showed 55 unique request headings, including request
`#1`, with no remaining older-page control. Selecting `Historical / unknown`
correctly showed zero matches on the newest page while preserving `more
available` and explaining `No matches in this page. Load older work to
continue.` Loading older then displayed only request `#1` as `Unknown`, `Not
verified`, and stated that the historical message is not an active assignment
and that new direct work must be sent to assign current responsibility. This
closes only the exercised 50+5 pagination/no-duplicate and unknown-baseline
presentation path; multi-project scale, edit/tombstone, responsive,
accessibility, and overall P1.1/P1.2 remain open.

## Edit, soft-delete, and offscreen evidence probe

An eighth disposable Compose stack served the same exact `771a222` application
image and MySQL 5.7.44 at `127.0.0.1:18090`, with fresh isolated volumes and
two independently authenticated synthetic humans. The owner created one direct
request and 51 newer direct requests so the exercised request was absent from
the installed timeline's first 50 rows and appeared only after `Load older
work` in the Responsibility Inbox.

The owner edited the exercised canonical message once. The installed Inbox
retained exactly one responsibility card for the request after pagination, and
`View original message` used the offscreen canonical fallback. Its installed
dialog preserved message ID `#1`, project sequence `1`, sender, addressee,
timestamp, and top-level thread identity; it displayed the edited body and
`1 revision; Current visible revision` without changing the Inbox filters or
creating another responsibility record.

The owner then soft-deleted the same message. Reopening the same Inbox card's
canonical evidence retained the same message and sequence identity, displayed
`1 revision`, the removal timestamp, `historical evidence retained`, and the
explicit tombstone text `This message was removed. Its historical identity and
responsibility evidence remain.` The authenticated API independently returned
one revision row, a non-null `deleted_at`, and exactly one retained
responsibility projection for request `#1` across the 50 + 2 cursor pages.

This closes only the exercised installed edit/one-revision/soft-delete and
offscreen canonical-fallback path. Multiple edits, reply-parent tombstones,
other actor/role variants, responsive/accessibility, and overall P1.1/P1.2
remain open pending Assessor review.

These probes exercised authenticated installed browsers against running
Docker apps and databases. The source/CI workflow for the first probe's commit
`7f69fda` also passed `source-contract`, `security-inventory`, and Docker
`source-acceptance` in GitHub Actions run `35381942910`.

## Still open

- Other role variants around transfer, dispute, and reopen. The exercised
  transfer/orphan recovery and requester-dispute/reopen flows are bounded paths
  through the broader role/state matrix.
- Broader stale HTTP 409 recovery across other roles and states, including
  refresh-failure UX. The owner-side withdrawal conflict above is exercised
  and accepted at narrow scope.
- Broader multi-project scale beyond the exercised single-project 50+5
  pagination/no-duplicate and historical unknown-baseline path.
- Broader evidence-history variants beyond the exercised one-edit,
  soft-delete, and offscreen canonical-fallback path, including multiple edits
  and reply-parent tombstones.
- Responsive-density, keyboard, focus, and screen-reader acceptance on the
  installed client.
- Assessor review of the remaining acceptance matrix.

The disposable environment is local test data. Its credentials and one-time
agent claim code are intentionally excluded from this record.

## Review disposition

Commercial Assessor accepted the owner-side positive path and installed
evidence navigation only at isolated-probe scope in project message #2781,
and the exercised two-identity requester/responder positive path at the same
bounded scope in #2783. Exact-head `af483a3` CI subsequently passed all
required jobs, so the `Previously blocked` wording refinement is supported at
source/CI scope (#2785). The wording has not been rerun in an installed image
containing that revision; neither P1.1 nor P1.2 is closed overall.

The Assessor subsequently accepted canonical reload/idempotent retry for the
exercised owner-side path and stale conflict at installed API scope in #2791.
For the two-browser rerun, #2795 accepted installed browser stale-action
conflict refresh/no-side-effect UX only for the exercised owner-side path in
exact image `d9ccd63`. GitHub Actions run `35398146572` then passed all
required jobs at evidence/checklist head `92cbb1a` (#2796). The remaining
installed matrix and overall P1.1/P1.2 gates remain open.

The Assessor accepted the installed `Previously blocked` wording sub-gate for
the exercised resolved path and exact `d9ccd63` image in project message
#2803. Evidence/checklist head `e961a95` then passed all three required V1 CI
jobs in run `35401728565` (#2804). This does not close the broader historical,
accessibility, P1.1/P1.2, merge, or release gates.

The Assessor accepted installed transfer, orphan derivation, and explicit
reassignment/recovery for the exercised three-human path in #2809. Exact
evidence head `db45f36` passed all required jobs in run `35408890560`.
The Assessor then accepted requester dispute, revised proposal, acceptance,
and explicit reopen for the exercised two-human path in #2811. Exact evidence
head `4595cb9` passed all required jobs in run `35410305194`. These decisions
do not close additional role variants, pagination/history/unknown baseline,
edit/tombstone, responsive/accessibility, full P1.1/P1.2, merge, or release.

The Assessor accepted the installed single-project cursor-pagination and
historical unknown-baseline sub-gate for the exercised 50+5 path in #2815.
Exact evidence head `a3f0d86` passed all required jobs in run `35412134767`.
The acceptance is deliberately bounded: it does not prove multi-project scale,
very large histories, edit/tombstone fidelity, responsive/accessibility, full
P1.1/P1.2, protected-main merge, or release.
