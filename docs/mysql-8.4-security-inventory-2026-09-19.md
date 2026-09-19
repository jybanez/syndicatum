# MySQL 8.4 image security inventory — 2026-09-19

This record compares the accepted MySQL 8.4 functional candidate with the
legacy MySQL 5.7 image on retained scanner evidence and records the subsequent
package hardening. It is not an external-deployment approval.

## Exact candidate evidence

- Protected-main parent: `27d8010436eaca23fd85bb8a0275fc3a31c75cae`
- Inventory branch head: `7652040e5ba505def5b9010658d4e876de96c331`
- GitHub PR merge-ref tested: `f365be75ee9283ffb70abd3a40606b66bbf1e2ad`
- Checksummed archive: `syndicatum-f365be75ee9283ffb70abd3a40606b66bbf1e2ad.tar.gz`
- Archive SHA-256: `9930a0a3cc7e499d9f4d7797ea1e13a29405154dae15bb59cf9e1b07ed62963c`
- CI run: `35443821368`
- MySQL 8.4 job: `105899217299`
- Pinned MySQL index digest: `sha256:85b9bf2e29cf836ecb8c2a15a935d4ba0c606631dff1dd79531a11983c638f2a`
- Built amd64/Linux database image ID: `sha256:a25f4c739f212affa18f30d44558c5c0e3bffce368f2b88e42d81cdba3a1cd67`

The workflow retained the image identity, a safe count summary, and a
row-level TSV with target, package type, package, installed/fixed versions,
advisory identifier, and severity. It did not retain the raw scanner report.

## Inventory result

The exact MySQL 8.4 candidate reported:

- 1 CRITICAL finding;
- 53 HIGH findings; and
- 28 MEDIUM findings.

This is materially smaller than the RC1 MySQL 5.7 image inventory of 4
CRITICAL, 97 HIGH, and 88 MEDIUM findings, but counts alone do not close a
security gate.

The one CRITICAL row is CVE-2025-68121 in the Go standard library statically
linked into `/usr/local/bin/gosu` (`v1.24.6`). The same advisory was already
classified `not_applicable` for RC1's older `gosu`: exploitation requires TLS
session resumption with a mutated `tls.Config`, while the privilege-drop helper
does not establish TLS and only switches identity before executing MySQL. The
updated binary still requires exact-symbol and supported-path review before the
8.4 row inherits that disposition.

The HIGH groups are:

| Package/target | Rows | Review boundary |
| --- | ---: | --- |
| Go `stdlib` in `/usr/local/bin/gosu` | 21 | Run exact-binary `govulncheck`; review only reported vulnerable symbols against the privilege-drop/exec contract. |
| `openssl` + `openssl-libs` | 18 | Exact Oracle Linux package rows have listed patched versions; determine MySQL runtime reachability and prefer a maintained upstream digest/package update over risk acceptance. |
| `libevent` | 8 | Exact Oracle Linux package rows have listed patched versions; verify whether MySQL links/uses the affected paths and prefer an updated image. |
| Python `cryptography` | 3 | Determine whether these packages are runtime dependencies or build/administrative tooling outside the supported MySQL path. |
| Python `urllib3` | 2 | Determine whether any supported runtime performs attacker-influenced HTTP through this package. |
| Python `pyOpenSSL` | 1 | Determine whether the supported MySQL runtime imports it; prefer removal/update if it is tooling-only. |

## Exact helper review

The pinned upstream image contains `gosu` 1.19 built with Go 1.24.6 for
linux/amd64. The extracted helper SHA-256 is
`52c8749d0142edd234e9d6bd5237dff2d81e71f43537e2f4f66f75dd4b243dd0`.
Binary-mode `govulncheck` v1.7.0 against those exact bytes reported zero called
vulnerable symbols and zero vulnerabilities affecting the binary. It separately
reported vulnerable imported packages/modules; that package-level context is
not a claim that the executable calls their vulnerable symbols.

Commercial Assessor message 2883 approved `not_applicable` for the one scanner
CRITICAL row and all 21 scanner HIGH Go-stdlib rows for this exact helper. The
approval does not cover the host's Docker/containerd/runc, and any change to the
pinned image, helper bytes, architecture, version, or build reopens the tranche.

## Hardened candidate

The maintained `mysql:8.4` tag still resolved to the already pinned digest, so
there was no newer upstream image to adopt. Oracle Linux's maintained repository
did contain the scanner-listed fixes. The derived image therefore:

- pins the upgrade to `libevent-2.1.13-1.el9_8`;
- pins `openssl` and `openssl-libs` to `1:3.5.8-1.0.1.el9_8`; and
- removes the unused `mysql-shell` package and its private Python environment.

Syndicatum runs `mysqld`, not MySQL Shell. The removed administrative client was
514.3 MB and contained every Python `cryptography`, `urllib3`, and `pyOpenSSL`
HIGH row. The MySQL entrypoint still retains the OpenSSL command needed for its
supported initialization behavior, while `mysqld` retains its patched OpenSSL
libraries. The 5.7 base has no `microdnf`, so this conditional hardening step is
a no-op on the immutable RC1 compatibility path.

Full exact-merge-candidate CI run `35448870803` passed source contracts, the
unchanged 5.7 lifecycle, 5.7-to-8.4 migration, and the hardened 8.4 lifecycle.
Its MySQL 8.4 job `105912435630` tested merge-ref
`373abee34d02a9e42a57bbcf95785c60e18a850d` (parents protected main `27d8010`
and branch head `25192f5`) from archive SHA-256
`26f3c463df714ca26e49c2a7862e62402df514165cfa607fd3eb458aa358905f`.
The built amd64/Linux database image ID was
`sha256:dac6b1c1585fce8ff0a5b862604577c4d2813b0af53785fc64b68f0bb278b161`.

The post-hardening inventory is 1 CRITICAL, 21 HIGH, and 22 MEDIUM findings.
Every remaining CRITICAL/HIGH row is one of the exact `gosu` Go-stdlib rows
approved `not_applicable` in message 2883. The OpenSSL/libevent and MySQL Shell
Python CRITICAL/HIGH rows fell to zero; no residual-risk acceptance is proposed.

## Gate status

The MySQL 8.4 functional and logical-migration sub-gates remain closed for
their tested scope. All remaining CRITICAL/HIGH rows in the hardened candidate
have an exact-binary approved disposition, so the MySQL 8.4 image-security
sub-gate is ready for independent closure review. The external-host/runtime and
overall promotion gates remain open; a green scanner job and lower counts do
not substitute for those separate controls.
