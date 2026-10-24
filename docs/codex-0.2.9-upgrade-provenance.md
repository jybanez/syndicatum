# Codex 0.2.9 upgrade provenance

The published lightweight tag `codex-v0.2.9` resolves directly to reviewed commit `4c2d2b64154bb8e111778dbc9defcfce1eff8371`. It has no peeled `^{}` reference. Do not move or replace the tag. The release archive checksum is `e3d78266ba78b6fe18c8b1092f90fb6a6a92222d25e5868ab917a58bc6d700f1`.

The corrected lifecycle helper queries both exact direct and peeled references. It requires one direct row, permits at most one peeled row, and rejects malformed, unexpected or duplicate rows. For annotated tags it compares the peeled revision; for lightweight tags it compares the direct revision. The resolved revision must match the unchanged pinned release commit exactly. This correction does not weaken backup, quiescence, Inspect, RejectFixture, Apply or no-replay checks.

## Helper byte serialization

Git exports and Windows checkouts may use different line endings. Verify the chosen serialization against its specific SHA-256; never accept an unexplained mismatch or silently convert already verified files.

| Helper | UTF-8 without BOM, LF | UTF-8 without BOM, CRLF |
| --- | --- | --- |
| `codex-windows-lifecycle-backup.ps1` | `b69f8d0f53cf3cf2247193f6aefd75926cc238c16c0d4f20331ce9e861cbc80b` | `18e678d1e78eb7ee83e15ccd0b74795deb93a2312b2ea215d3781167eb073e12` |
| `codex-windows-lifecycle-transition.ps1` | `798da9ddb50835c32125cbea16b5d87c8c2cfbe25a494e61d3397fcfe479c0a9` | `cc67656eae4a913da2321501ba91b17f8294ca4bab1311ec179769e06fcdf33b` |

These hashes identify helper content, not the installed release revision. The helper source must also come from the reviewed correction commit. A changed helper requires a new source/hash review.

## Offline procedure

Withdraw the previous peeled-only helper and any prepared runner that pins it. Retain earlier preparation and failed-attempt artifacts. Do not reuse an attempt directory.

Each device prepares its own external backup and one-time runner using its own paths. Fully exit Codex, stop only the verified Syndicatum Connector Scheduled Task, and verify the listener process and file locks are released. Use the external verified backup manifest for Inspect, RejectFixture and one Apply targeting 0.2.9. Stop on any mismatch or uncertain outcome; do not replay. Restore the previously running listener afterward. Record exact installed revision/bytes, advancing health, preserved credentials/bindings/history, and healthy-route delivery while a missing destination is isolated. Publication or source review alone does not establish installed acceptance.

`tests/codex-remote-tag.test.ps1` loads only the pure verifier from the helper AST. It accepts the two tag formats and rejects malformed, wrong, missing and ambiguous responses without loading the installer or running plugin commands.
