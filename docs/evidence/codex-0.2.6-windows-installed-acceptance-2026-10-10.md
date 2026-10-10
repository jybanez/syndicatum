# Codex 0.2.6 Windows installed acceptance

## Scope

This record summarizes the commercially reviewed installed/fresh-task acceptance
of immutable release `codex-v0.2.6` at source commit
`c2ed0247fd61dc51bfa8515b1b3882f6a440aa1c` on the two owner-approved target
machines. It does not establish active routed delivery or production support.

## Provenance

- Release ZIP SHA-256: `f0c067e98a92e05fbc16531eb12173b122054827cd83f30dbf2096d59e7a9788`
- Provenance manifest SHA-256: `508b0d3e4d425c70a79ce174c4b601771dc58e6f967f299104e52deef8ef937f`
- Marketplace inventory: 46 paths
- Installed inventory: 45 paths, with no unexpected installed files
- Installed MCP version: `0.2.6`
- Installed tool catalog: 38 tools

## Accepted evidence

HomeServer Windows 11 and S5 Windows 10 Pro 22H2 both verified exact release and
installed bytes, protected project-scoped identity and authoritative reads,
listener/runtime ownership, recovered routing state of 40 bindings and 8
projects with 0 unavailable bindings, and advancing heartbeat freshness.

HomeServer retained 15 passing contained installed-code checks covering
configuration-change route preservation, listener reload scheduling, and
fail-closed aggregate health. S5 retained 41 passing installed checks plus
recovery verification and a genuinely fresh-task validation.

## Qualifications

- Active routed delivery was not exercised. The separate historical HomeServer
  target-thread `no rollout found` condition remains unresolved.
- S5 preserved all unrelated configuration except a withheld runtime-managed
  `SKY_CUA_NATIVE_PIPE_DIRECTORY` difference.
- S5 was healthy through the Run-key fallback; its retained Scheduled Task state
  was not accepted as a reliable startup path.
- Contained reload and negative-health fixtures verify installed-code behavior,
  not a live protected configuration mutation or induced failure.
- Publication and installed acceptance do not create production support,
  promotion, a supported predecessor, or a `production_range`.

## Subsequent finding

After this gate closed, a new protected-profile claim was blocked before the
Syndicatum request because the task checkout contained the obsolete
`pbb-chat-token.local.json` format. Candidate `0.2.7` removes that migration
dependency; the historical file is ignored and preserved.
