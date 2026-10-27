# Active resolution: Developer host only

Owner message 11522 and Commercial message 11525 supersede the multi-machine
pilot path. This document is the active acceptance plan. HomeServer, S5 and S6
are deferred. Their preimages, malformed-route assumptions, old CLI binary and
disposable-test prerequisites are not acceptance requirements for this machine.
Historical PR #160 pilot contracts remain uninvoked reference work. Do not use
their hardcoded identities to validate or mutate this Developer installation.

Attempt 11545 subsequently stopped at preflight with zero native mutations
(report 11546). Its authority is exhausted. See
[the proposed freshness/cutover policy](codex-developer-freshness-policy.md) for
source-only preparation of a new packet; neither path is yet selected/authorized.

## Fresh read-only baseline, 2026-10-25

- Windows 11 Home Insider Preview Single Language, build 26220.
- CLI 0.164.0-alpha.11.1, binary SHA-256
  `1103e9e09c71fa2cd0afd6d8f3b9a2d63174fe8efd7cb5bd09f1cb14ef58a098`.
- Codex desktop package 26.1021.3705.0; a desktop app-server process is running.
- Marketplace `syndicatum`: Git source
  `https://github.com/jybanez/syndicatum.git`, configured ref `codex-v0.2.7`,
  managed checkout revision `68c586b17628b387f08e42da3f17199f0f8824e7`.
- Plugin `codex@syndicatum` enabled; installed cache manifest version 0.2.7.
- Development main is `6ec6ac1c1b1b15a1c3f4db19c4a486477c15b0aa`, manifest 0.2.10.
  The source tree is not the installed/cache/runtime copy.
- Installed and copied runtime background-service bytes match SHA-256
  `f00a2508f42573f6301e04556fc5f7e9910d5d1d8d6bfa9b632806648a95249e`.
  Current development source differs.
- Old background process PID 2492 owns both lock files. Two health observations
  at 01:04:55.397Z and 01:05:10.451Z advance for that PID, reporting state
  `running`, 54 bindings, 12 projects, and zero unavailable bindings. This old
  health schema does not establish per-route health or new lifecycle acceptance.
- Exact Scheduled Task `Syndicatum Codex Connector` is running, interactive
  principal, one logon trigger, one action invoking its generated launcher.
- Exact HKCU Run value `SyndicatumCodexConnector` is present and invokes the same
  generated launcher. Both are plugin-specific legacy autostart objects.
- `Syndicatum Realtime Outbox` is a separate hosted-service task and excluded.
- Correction after exact filename classification: 56 canonical
  `state.json.participant-<digits>` files, all valid JSON, including participant
  36 for this protected profile. The extra malformed file is a `.bak` artifact,
  not a canonical state file: 4841 bytes, SHA-256
  `65b652e7c47273b34785c402e2395cfb29e30eccaf5acbe18c26af7faf30e04d`,
  mtime_ns 1791954068081785000. No repair, deletion or replay is proposed.
  `DeviceConnector` constructs the exact numeric participant filename, not a
  glob, so that backup artifact is not selected as active route state. This does
  not itself prove route delivery. No remote-machine assumptions apply.

## Shortest proposed one-machine path (review required before mutation)

1. Revalidate this exact local binary/config/cache/task/Run/process/lock baseline.
   Retain private snapshots and a verified backup outside webroot of only the
   local plugin configuration, runtime and protected bindings/state. Preserve
   unrelated plugins and hosted services; do not publish protected raw data.
2. Stage the reviewed 0.2.10 plugin source from exact commit
   `9141daa08d896f2524be30b96d01ab2a14398757`. Verify package inventory and bytes.
   Do not install the mixed development working tree or an older remote payload.
3. Disable/remove only the exact local legacy connector Task/Run registrations
   after review of their private preimages, then stop only its verified owned
   process. Preserve state and evidence. Recheck independent quiescence.
4. Prefer retaining Git as the marketplace source type. The current CLI's named
   `marketplace upgrade syndicatum` only refreshes the configured old ref; it
   cannot select the desired commit. Proposed bounded alternative: native CLI
   removal of only `syndicatum`, independent absence/unrelated-entry checks,
   native Git marketplace add with the exact reviewed commit supplied via
   `--ref`, then `plugin add codex@syndicatum`. Review command/cache semantics
   against this exact CLI before final execution approval. No broad upgrade,
   config hand edits, wildcard removal, or automatic retry/rollback.
5. Independently verify managed source revision, installed 0.2.10 file inventory,
   copied runtime, enabled plugin, preserved protected state and unrelated entries.
   Use supported plugin reload/normal owner reopen for activation. Do not launch
   Codex on the owner's behalf after deliberate closure.
6. Accept one healthy selected local route through the protected profile and
   canonical timeline. Use separately authorized test-message IDs; prove receipt,
   one delivery, handling and acknowledgement without replaying existing history.
   An aggregate heartbeat alone is insufficient. Report any other unavailable
   routes explicitly rather than claiming they were recovered.
7. Verify two owning sessions share one listener; closing one keeps it alive;
   last-owner closure stops it within the source-defined bound and leaves Codex
   closed; one pending authorized message remains unacknowledged while closed
   and is delivered once on the owner's next normal opening. This final closure
   test needs an observer outside the closing Codex session; do not infer it from
   the current chat or force a reopen to collect evidence.
8. Confirm no connector Task/Run/logon autostart returns. Record exact installed
   revision, checks, route result and lifecycle evidence in Syndicatum.

This plan is not an execution adapter or authority. Next review must decide the
exact Developer-only steps and current CLI semantics. A server, credentials,
unrelated hosted-service change or broader destructive cleanup requires a new
narrow owner decision. No work on other machines is needed for this acceptance.
