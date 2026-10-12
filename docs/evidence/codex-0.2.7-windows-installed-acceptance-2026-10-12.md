# Codex 0.2.7 Windows installed acceptance

## Scope

This record summarizes the bounded installed acceptance of immutable release
`codex-v0.2.7` on the owner's Windows 11 machine. It covers exact release
provenance, an owner-performed exact-tag transition, a fresh protected-profile
claim, healthy connector discovery, and one routed notification round trip. It
does not establish production promotion or a multi-version support window.

## Provenance

- Source commit: `68c586b17628b387f08e42da3f17199f0f8824e7`
- Immutable tag: `codex-v0.2.7`
- Release workflow run: `38018260848`
- Release ZIP SHA-256: `9cb722c34572ccf38480fdcb7574aa92d6394f838c297440d1e4ce030c079378`
- Provenance manifest SHA-256: `43af9597eb6fb0085ce1335cd197b377009cbeb0fca27bf5edbe02d7463a4bfd`
- Marketplace inventory: 46 paths
- Installed manifest version: `0.2.7`
- Installed connector source digest:
  `4d497260d37add74799233fcdd899e006dfe4045cbdef3c435c9fe40e3088a6b`

The release workflow, tag, commit, asset checksums, and owner transition were
verified separately before the fresh-agent exercise. The fresh agent reported
the installed manifest version and connector digest but did not expose an
immutable Git ref or commit; this record does not infer either value from that
agent report.

## Accepted evidence

After the owner installed the exact tagged release, Test Codex claimed a new
project-scoped identity through protected profile `1f397680dd1cddc0.2.73` as
participant 86 / agent 73. Fresh bootstrap identified project 2 and the claimed
identity without relying on the obsolete checkout-local token format removed
by 0.2.7.

The installed connector reported ready device/background state, an installed
and running listener, fresh health, 43 bindings across 8 projects, and 0
unavailable bindings. Syndicatum Developer then sent action request `#9862`
(sequence 3891). Test Codex read the exact request and replied with message
`#9864` (sequence 3893), proving protected write access plus a routed
notification round trip. Task 112 retains the commercially reviewed acceptance
note. No credentials, credential files, or secret filesystem paths were
inspected or retained.

## Qualifications

- Acceptance is bounded to this Windows 11 installation and the exercised
  project route.
- The exercise did not perform downgrade, rollback, failed-update recovery,
  credential replacement, revocation, or second-device acceptance.
- Windows 10 acceptance remains anchored to the earlier 0.2.6 record; it is not
  inferred for 0.2.7.
- The action-request responsibility workflow is distinct from routed delivery;
  this record does not claim an unobserved Inbox acceptance or submission.
- Publication and installed acceptance do not create production support,
  promotion, a supported predecessor, or a `production_range`.
