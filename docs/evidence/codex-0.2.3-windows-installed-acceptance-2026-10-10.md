# Codex 0.2.3 Windows installed acceptance — 2026-10-10

Status: partial acceptance on Windows 10 Pro 22H2 and Windows 11. The exact
tagged marketplace and installed package bytes passed, including an isolated
installed-server handshake on Windows 10. Production support remains closed.

## Artifact identity

- release: `codex-v0.2.3`;
- source commit: `5f1f7439e39d374ad570d185d6181d492bcd5a70`;
- release ZIP SHA-256:
  `3d53dc0bb1440a5c53102ac463eda2c12b3d427afe647181817096c61dcd6942`;
- provenance manifest SHA-256:
  `cad9e88881b9c8363ac33f336212038f2899384f43d622fd40dcf2e6e84b0a1b`;
- package inventory: 44 marketplace paths and 43 installed plugin paths.

## Windows 11 observations

- exact ref, source commit, release hashes, and all package bytes passed
  without line-ending normalization;
- `codex@syndicatum` reported `0.2.3`, installed and enabled after Desktop
  reopened;
- all 38 expected profile-bound MCP tools were exposed;
- the protected public identity and project-2-only visibility remained
  unchanged, and authoritative project/task/message reads passed;
- the background connector reported ready/running/listener ownership with a
  matching live process and the installed `0.2.3` source;
- the offline helper stopped before claiming success because the pre-reopen
  plugin list retained the prior version. The post-reopen state was verified
  read-only; no automatic retry or forced install occurred.

## Windows 10 observations

- a verified 679-file marketplace/plugin recovery snapshot and two opaque
  configuration backups were retained without exposing their values;
- the exact-tag marketplace transition completed with seven retained native
  exit records at zero and empty stderr;
- all marketplace and installed package bytes passed without normalization;
- after reopen, `codex@syndicatum` reported `0.2.3`, installed and enabled;
- an isolated child launched the exact installed server with a new empty
  temporary plugin-data directory and an empty child-only token. JSON-RPC
  `initialize` returned `syndicatum-connector` / `0.2.3`, and `tools/list`
  returned the exact 38-tool catalog. The child did not access live connector
  data or call configuration tools;
- the protected public identity, project-2-only visibility, authoritative
  reads, and live connector ownership remained intact.

## Open acceptance limits

- `background-health.updatedAt` was written at startup but did not advance, so
  health age was not directly measurable even though the live PID and listener
  owner matched. Candidate `0.2.4` adds a bounded heartbeat and rejects stale
  health by age;
- no designated non-destructive cross-project fixture existed. No alternate
  protected identity or project was selected to manufacture a negative test;
- same-version recovery was not exercised, although verified recovery
  snapshots were retained;
- the Windows 10 follow-up remained in the original discussion, so a distinct
  fresh-task boundary was not independently established;
- credential rotation/revocation and a supported predecessor rollback were not
  attempted.

## Safety and disposition

No credential contents, protected profile data, queue/history item, server
configuration, or unrelated marketplace registration was read or changed.
Release `0.2.3` remains published but unpromoted and is not production
supported. Exact installed acceptance must be repeated for the heartbeat
candidate before health freshness can pass.
