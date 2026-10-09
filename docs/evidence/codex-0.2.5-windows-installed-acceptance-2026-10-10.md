# Codex 0.2.5 Windows installed acceptance

Status: qualified evidence; published but unpromoted and not production supported.

## Exact artifact

- Release commit: `03c6688a8e9adf6add9e0917d0a24cebf7c83ff8`.
- Release ZIP SHA-256: `18be1fb66e9a1472c3b326aed542bd63847ed44e1d3763528fb731dfc1af7365`.
- Manifest asset SHA-256: `d2f68534e4ac45f0e5c9fbb8375263e0cefe1d17b4ece4680070d09626fedd90`.
- Both machines verified all 46 marketplace provenance paths and all 45 installed files with no extra installed files.

## Windows 10 22H2

S5 verified installed/enabled `0.2.5`, an isolated `syndicatum-connector/0.2.5`
handshake, exactly 38 tools, protected identity and project-2 scope,
authoritative reads, listener ownership, and an advancing fresh heartbeat. A
contained stale-health fixture produced `stale_health` from both
`BackgroundServiceManager` and top-level `PluginRuntime.currentStatus`. The
post-reopen configuration difference was limited to the runtime-managed
`SKY_CUA_NATIVE_PIPE_DIRECTORY` setting; values were not retained or exposed.

## Windows 11

HomeServer verified the same artifact, installation, catalog, identity/scope,
authoritative reads, listener ownership, heartbeat advancement, and contained
stale-health propagation. The acceptance remained qualified because a later
fresh health record changed from `running` with 38 bindings across 7 projects
to `configured` with zero bindings and projects while readiness still reported
ready.

A bounded read-only follow-up established that the zero counters persisted
across three fresh heartbeat records. The connector configuration-file watcher
replaced the live status object with a minimal configured-only object, dropping
the counters; the heartbeat then serialized missing counters as zero. The
specific watcher event source was not established. Sanitized connector logs
also showed delivery attempts failing because the bound Codex target thread had
no rollout. No restart, reinstall, configuration or credential change, queue
mutation, transition retry, rollback, or injected delivery test was performed.

## Result

Version `0.2.5` proves the aggregate stale-health correction but does not close
the Windows production gate. Candidate `0.2.6` reloads the listener after a
configuration change, preserves route counters while reload is pending, and
treats configured-only or reload-pending background health as not ready. Exact
tagged installed acceptance and routed delivery through a valid binding remain
required before promotion or a production-support claim.
