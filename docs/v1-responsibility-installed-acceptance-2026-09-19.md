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

These observations exercise an authenticated installed browser against a
running Docker app and database. The source/CI workflow for the same commit
also passed `source-contract`, `security-inventory`, and Docker
`source-acceptance` in GitHub Actions run `35381942910`.

## Still open

- Responder-side acknowledge, unresolved, blocked, dispute, handoff, and
  resolution flows under independently authenticated identities.
- Stale HTTP 409 conflict UX and same-key retry proof in the installed client.
- Paginated multi-project/no-duplication behavior and historical/unknown data.
- Evidence navigation after edit and soft-delete, including an offscreen row.
- Responsive-density, keyboard, focus, and screen-reader acceptance on the
  installed client.
- Assessor review of this installed probe and the remaining acceptance matrix.

The disposable environment is local test data. Its credentials and one-time
agent claim code are intentionally excluded from this record.
