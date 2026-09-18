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

These probes exercised authenticated installed browsers against running
Docker apps and databases. The source/CI workflow for the first probe's commit
`7f69fda` also passed `source-contract`, `security-inventory`, and Docker
`source-acceptance` in GitHub Actions run `35381942910`.

## Still open

- Responder-side dispute, handoff, orphaned/transfer, and reopened flows under
  independently authenticated identities. The observed acknowledge/start/
  blocked/propose/accept sequence is only one path through the matrix.
- Broader stale HTTP 409 recovery across other roles and states, including
  refresh-failure UX. The owner-side withdrawal conflict above is exercised;
  it is pending Assessor review at this exact-build scope.
- Paginated multi-project/no-duplication behavior and historical/unknown data.
- Evidence navigation after edit and soft-delete, including an offscreen row.
- Responsive-density, keyboard, focus, and screen-reader acceptance on the
  installed client.
- Assessor review of this installed probe and the remaining acceptance matrix.

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
