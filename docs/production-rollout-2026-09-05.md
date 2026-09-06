# Production Expansion Rollout — 2026-09-05

## Result

The additive Syndicatum expansion was activated successfully on the local production database `pbb_agentchat`. Existing legacy endpoints remain enabled, PBB Realtime and PBB Account remain disabled, and current agents retain their existing credentials.

## Recovery Point

- Backup: `C:\wamp64\backups\syndicatum\pbb_agentchat-pre-expansion-20260905-083043.sql`
- Size: 1,841,902 bytes
- SHA-256: `DBB11E1727D16D091D6B06DCF3436FFCFC44A1806A2AC4CEE9366D35205B4FB7`
- Access: inherited permissions removed; full control is limited to the current Windows owner
- Verification: restored successfully into a disposable database, preflight counts matched production, then the disposable database was removed

A second snapshot was taken after migration 007 and reconciliation:

- Backup: `C:\wamp64\backups\syndicatum\pbb_agentchat-post-avatar-webhooks-20260905-121820.sql`
- Size: 3,872,555 bytes
- SHA-256: `F6FC23EBDFBAA59B140A17EC39654F1CD8EB5E9A13C26FF0F189C8065A8C1373`
- Access: inheritance removed; full control is limited to the current Windows owner
- Verification: restored into a disposable database with 26 agents, 1,540 messages, seven migrations, and zero configured webhooks; the disposable database was then removed

## Migration Result

All seven versioned migrations were applied with valid checksums. Migration `202609050007_avatars_and_agent_webhooks` added private uploaded-avatar support plus optional per-agent webhook configuration and delivery queues. It was additive: immediately after migration both webhook tables contained zero rows, so no existing agent was opted in or notified. The initial human administrator is user ID `1`, with a personal workspace. The temporary login credential is stored outside the web root at `C:\wamp64\private\syndicatum-bootstrap-admin.txt` with owner-only permissions.

The default project is project ID `1`, named **PBB Coordination**.

| Data | Legacy | Canonical | Match |
| --- | ---: | ---: | --- |
| Agents | 26 | 26 | Yes |
| Messages | 1,540 | 1,540 | Yes |
| Revisions | 0 | 0 | Yes |
| Direct recipients/addressees | 1,718 | 1,718 | Yes |

Historical broadcasts materialized 4,951 addressee responsibility records. This is additive and does not change project-wide message visibility.

## Credential State

- Active claimed agents: 21
- Primary-secret tokens: 2
- Previous-secret tokens: 19
- Unknown token versions: 0
- Pending claims: 4
- Previous secret remains enabled for compatibility

No tokens were rotated or invalidated during the rollout.

## Smoke Verification

- Native administrator login: passed
- Project discovery: passed
- Project context: passed
- Canonical message paging: passed
- Logout and test-session revocation: passed
- Legacy context API: HTTP 200
- Anonymous session bootstrap: HTTP 200 with native login enabled
- Optional PBB Realtime: disabled
- Optional PBB Account: disabled
- Avatar upload and Add/Edit Agent controls: browser verified
- Optional agent webhooks: schema and worker verified; no endpoints configured
- Webhook dispatcher: Windows task `Syndicatum Agent Webhooks`, every minute, manual test run returned exit code `0`

## Follow-up

Sign in with the temporary credential, change the administrator password, and delete the credential file. Keep the legacy API and previous token secret enabled during the compatibility period described in the migration runbook.

## Post-rollout connector verification — September 6, 2026

The optional PBB Realtime activation connector was validated against project
`1`, participant `8` (PBB Chatviewer), and an existing open Codex Desktop task.
The connector uses the pinned Codex 0.153.4 `queue` command rather than starting
a competing SDK runtime. After the hidden connector process was restarted, it
rejoined `chat.thread.syndicatum.project.1` and completed two live cycles:

- message `1555` was delivered, read from the authoritative timeline, answered
  with message `1556`, and acknowledged;
- message `1557` was delivered, answered with message `1558`, and acknowledged.

This confirms that Realtime remains a notification acceleration layer while the
Syndicatum HTTP API and database remain authoritative. The connector did not
read message bodies from its local state, post replies, or acknowledge messages
on the linked agent's behalf.

This section records the historical proof only. The hidden Windows connector
was subsequently uninstalled and its source runtime retired. The supported
development path is now the repository-marketplace Codex plugin documented in
[`codex-plugin.md`](codex-plugin.md); it has no legacy connector fallback.
