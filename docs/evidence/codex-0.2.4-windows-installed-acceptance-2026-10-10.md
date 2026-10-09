# Codex 0.2.4 Windows installed acceptance — 2026-10-10

Status: qualified acceptance on Windows 10 Pro 22H2 and Windows 11. The exact
tagged marketplace and installed package bytes, advancing background heartbeat,
and background-manager stale-health rejection passed. Aggregate connector
status failed closed only after the `0.2.5` source correction, so `0.2.4`
remains unpromoted and unsupported.

## Artifact identity

- release: `codex-v0.2.4`;
- source commit: `3ac16eb7a882da05d95974905b70f96407e9f36c`;
- release ZIP SHA-256:
  `6bfa999de478194bf0a3f4df1fa86e7d0ba09fc762f4d79c7dbd2b0865244b90`;
- provenance manifest SHA-256:
  `f2a0fea417ac48ecc8e792aaa27167731a81bb4c5e65cef9d7ad60074422f19e`;
- package inventory: 46 marketplace paths and 45 installed plugin paths.

## Verified observations

- both target machines retained exact release and installed bytes and exposed
  `syndicatum-connector` version `0.2.4` with the exact 38-tool catalog;
- protected identity continuity, project-2-only scope, authoritative reads,
  live process/listener ownership, and ready fresh-health status passed;
- repeated samples showed the health timestamp advancing at approximately the
  configured 15-second interval;
- a contained temporary-data fixture with no real profile or credential proved
  the background manager reports `healthFresh=false`, `readiness=stale_health`,
  and `listenerReason=background_health_stale` for stale health while the
  synthetic process owns the listener;
- the same contained fixture exposed a remaining defect: installed
  `PluginRuntime.currentStatus()` returned top-level `state=ready` while its
  nested background readiness was `stale_health`.

## Safety and disposition

No credential value, private profile data, queue/history item, server
configuration, or unrelated marketplace registration was changed. Both
installations were frozen after evidence collection. Release `0.2.4` remains
published but unpromoted and is not production supported. Candidate `0.2.5`
must pass exact installed acceptance for the aggregate fail-closed correction.
