# Companion 0.10.24 installed acceptance — 2026-10-09

Status: passed for the unpacked pilot channel on Chrome/Windows 11. This is not
a Chrome Web Store, Edge Add-ons, automatic-update, or production-support
claim.

## Artifact and rollback evidence

- Application/server source: main commit
  `122e13c6fe175818fadd75058183f7dc556602f4`.
- Companion package: `0.10.24`.
- ZIP SHA-256:
  `74c646c5ac0c9fb2a5ba8b1c1fe0b6addf3a580b12fa2131c7db866024678de0`.
- Checksum-file SHA-256:
  `0cf0122e84986c69ae7e56c3951115a8495e94ab0a4feedb2fd35e1db513e7a9`.
- Archive paths were bounded to the extraction root and the installed tree was
  byte-for-byte identical to the verified source tree.
- A fresh complete 0.10.23 rollback backup was retained before replacement;
  bidirectional inventory and SHA-256 comparison reported zero mismatches.

## Installed runtime evidence

After reloading the same extension registration:

- the extension ID, server address, authorized account, and five discussion
  bindings were unchanged;
- Realtime rejoined successfully with two of two connections;
- Overall and Delivery were healthy;
- queued deliveries and review items were both zero;
- no current error remained;
- the prior `send_unavailable` diagnostic residue disappeared while the last
  delivery timestamp remained unchanged, proving the migration did not replay
  or submit a message.

## End-to-end delivery evidence

The isolated post-fix probe used Syndicatum message `8498`, project sequence
`3524`. The bound Commercial Assessor discussion confirmed the exact receipt
and produced one normal reply as message `8499`, sequence `3525`. The normal
final action released the bounded handling lease automatically. A later
HomeServer probe (`8500`/`3526`) received the requested single reply
(`8501`/`3527`) with the same receipt-to-reply behavior.

No historical delivery item, queue clear, retry, replay, separate
acknowledgement, binding change, credential change, or unrelated task action was
used for either probe.

## Boundary

This evidence accepts the exact unpacked 0.10.24 pilot installation and its
critical ChatGPT delivery path. Production classification remains fail-closed
until an unchanged provider-published package has a store identifier and passes
store-managed install, automatic update, rollback/recovery, migration,
revocation, and supported browser/OS acceptance.
