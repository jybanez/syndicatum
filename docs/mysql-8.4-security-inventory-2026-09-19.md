# MySQL 8.4 image security inventory — 2026-09-19

This record compares the accepted MySQL 8.4 functional candidate with the
legacy MySQL 5.7 image on retained scanner evidence. It is an inventory, not an
external-deployment approval or a finding disposition.

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

## Gate status

The MySQL 8.4 functional and logical-migration sub-gates remain closed for
their tested scope. The image-security and external-host/runtime gates remain
open. Wider promotion requires row-level disposition and either a maintained
upstream image/package refresh or evidence-backed reachability conclusions; a
green scanner job and lower counts are not substitutes for that review.
