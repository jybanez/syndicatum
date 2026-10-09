# Codex 0.2.2 Windows 11 installed acceptance — 2026-10-09

Status: not accepted for production support. The exact tagged marketplace was
restored and the plugin was functional, but exact-byte provenance and several
lifecycle gates remain open.

## Artifact identity

- release: `codex-v0.2.2`;
- source commit: `848d31d630a35e2e469c4b078d23ffa630593307`;
- release ZIP SHA-256:
  `3ef27dc63c43f8984b1a26d312b374e57508d620f3da169bc371a12fd9f0c25f`;
- provenance manifest SHA-256:
  `88dd07a6c231b1075c0151b7aeceee155beed393e8a8584228235078d1119157`;
- environment: Windows 11 Pro build 26100 and Codex Desktop/CLI.

## Passed observations

- the exact marketplace ref resolved to the expected source commit;
- `codex@syndicatum` reported version `0.2.2`, installed and enabled;
- all 43 expected package paths were present, while exact-byte verification
  passed only the two binary files and failed the 41 text files described
  below;
- tool discovery included the bounded project-file and public-HTTPS readers;
- the connector reported ready with its background service running and owning
  the listener;
- the protected HomeServer identity remained project 2, agent 32, participant
  35;
- profile-scoped project discovery exposed only project 2, and non-mutating
  authoritative bootstrap and task reads succeeded;
- an HTTP loopback URL was rejected because the public reader requires HTTPS.

## Failed or incomplete observations

- exact-byte provenance failed for 41 text files. Every mismatch disappeared
  after in-memory CRLF-to-LF normalization, with no other normalized
  differences. The marketplace checkout had `core.autocrlf=true` and the tag
  did not carry package-specific LF attributes;
- a live MCP `initialize` response exposing `serverInfo.version` was not
  retained;
- the verification occurred in a reopened chat rather than a separate fresh
  task;
- connector health was positive but its timestamp did not advance during the
  bounded observation window;
- no safe negative cross-project authorization probe was available without
  selecting another real protected profile or project;
- the interrupted update was restored, but the helper's premature metadata
  check and earlier exit-code defect mean same-version recovery acceptance was
  not established;
- revocation and credential replacement were not attempted.

## Safety and disposition

Snapshots and sanitized command evidence were retained. No protected profile
data or credentials were read, replaced, or deleted. No downgrade to `0.2.0`,
force operation, server/database change, or production-support promotion was
performed.

The `0.2.2` release remains published but unpromoted. Candidate `0.2.3` adds a
Git attributes contract and checkout regression test so Windows
`core.autocrlf=true` preserves the same package bytes used by release
provenance. Exact installed acceptance must be repeated for the new immutable
tag.
