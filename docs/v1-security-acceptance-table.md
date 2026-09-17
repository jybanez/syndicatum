# V1 container security acceptance table

**Status:** Triage in progress; no critical finding below is accepted or
dispositioned. This table is a release-gate record, not a claim of practical
exploitability. Source secret/dependency scan results are tracked separately in
[`v1-security-inventory-2026-09-18.md`](v1-security-inventory-2026-09-18.md).

**Evidence baseline:** [PR CI run 35259335422](https://github.com/jybanez/syndicatum/actions/runs/35259335422)
on the archived MySQL 5.7.44 candidate. The Docker acceptance artifact retains
`image-security-findings.tsv` with scanner target, package type, package,
installed version, scanner-listed fix version, CVE, and severity. This table
records its 19 CRITICAL package/CVE
findings individually. A blank scanner fix version means **not listed by the
scanner**, not proof that no fix exists. All applicability and residual-risk
judgments remain open until checked against the running image and advisory.
The process probe passed in [PR CI run 35261244225](https://github.com/jybanez/syndicatum/actions/runs/35261244225):
Apache had one UID 0 master (`CapEff=00000000000004c0`) and UID 33 workers
(zero effective capabilities); MySQL's running server was UID 999 with zero
effective capabilities, and the worker service was separately verified as
non-root UID 33. All probed processes had `NoNewPrivs=1`. The same run found
no enabled Apache CGI/Perl module and no Apache `libperl` linkage.
Thus the Dockerfile `USER` heuristic does not describe MySQL's steady-state
server privilege, but the Apache master does remain privileged. The Compose
stack uses `no-new-privileges:true`. In `compose.yaml`, the database has no
published host port and attaches only to the `internal: true` backend network;
the application publishes port 80 to host loopback by default (the bind
address is configurable), and the worker publishes no port. The database
volume is writable at `/var/lib/mysql`, and the app/worker share a writable
avatar volume at `/var/lib/syndicatum/avatars`. The application now drops all
default capabilities and adds only `NET_BIND_SERVICE`, `SETGID`, and `SETUID`;
the root master's effective set fell from `00000000a80425fb` in run
35258886273 to `00000000000004c0` without breaking clean install or restore.
No read-only root filesystem is configured. Whether the master can be made
non-root and whether the three retained capabilities can be reduced further
remain open.

[PR CI run 35263219092](https://github.com/jybanez/syndicatum/actions/runs/35263219092)
passed all three jobs after the Docker acceptance probe was changed from
logging these privilege values to failing on a regression: the Apache root
master must have only `CapEff=00000000000004c0`, its non-root workers and
`mysqld` must have zero effective capabilities, and all three process classes
must report `NoNewPrivs=1`. This protects the tested hardening baseline; it
does not resolve whether a non-root Apache master or read-only root filesystem
is feasible.

## CRITICAL findings

| CVE | Severity | Image / package | Reachable or exposed? | Fix available? | Planned action | Mitigation / residual risk | Acceptance |
| --- | --- | --- | --- | --- | --- | --- | --- |
| CVE-2026-13221 | CRITICAL | app / `libperl5.36` | No direct runtime path observed; completeness pending | None listed | Check transitive Perl execution and advisory path | Unassessed | Open |
| CVE-2026-42496 | CRITICAL | app / `libperl5.36` | No direct runtime path observed; completeness pending | None listed | Check transitive Perl execution and advisory path | Unassessed | Open |
| CVE-2026-8376 | CRITICAL | app / `libperl5.36` | Tested image is amd64; advisory is 32-bit-only | N/A for tested architecture | Confirm supported release architectures and independent review | Proposed N/A for amd64 only | Review pending |
| CVE-2025-7458 | CRITICAL | app / `libsqlite3-0` | Unknown | None listed | Check whether production PHP/Apache calls SQLite | Unassessed | Open |
| CVE-2026-6653 | CRITICAL | app / `libxml2` | Unknown | None listed | Check XML use and reachable input path | Unassessed | Open |
| CVE-2026-13221 | CRITICAL | app / `perl` | No direct runtime path observed; completeness pending | None listed | Check transitive Perl execution and advisory path | Unassessed | Open |
| CVE-2026-42496 | CRITICAL | app / `perl` | No direct runtime path observed; completeness pending | None listed | Check transitive Perl execution and advisory path | Unassessed | Open |
| CVE-2026-8376 | CRITICAL | app / `perl` | Tested image is amd64; advisory is 32-bit-only | N/A for tested architecture | Confirm supported release architectures and independent review | Proposed N/A for amd64 only | Review pending |
| CVE-2026-13221 | CRITICAL | app / `perl-base` | No direct runtime path observed; completeness pending | None listed | Check transitive Perl execution and advisory path | Unassessed | Open |
| CVE-2026-42496 | CRITICAL | app / `perl-base` | No direct runtime path observed; completeness pending | None listed | Check transitive Perl execution and advisory path | Unassessed | Open |
| CVE-2026-8376 | CRITICAL | app / `perl-base` | Tested image is amd64; advisory is 32-bit-only | N/A for tested architecture | Confirm supported release architectures and independent review | Proposed N/A for amd64 only | Review pending |
| CVE-2026-13221 | CRITICAL | app / `perl-modules-5.36` | No direct runtime path observed; completeness pending | None listed | Check transitive Perl execution and advisory path | Unassessed | Open |
| CVE-2026-42496 | CRITICAL | app / `perl-modules-5.36` | No direct runtime path observed; completeness pending | None listed | Check transitive Perl execution and advisory path | Unassessed | Open |
| CVE-2026-8376 | CRITICAL | app / `perl-modules-5.36` | Tested image is amd64; advisory is 32-bit-only | N/A for tested architecture | Confirm supported release architectures and independent review | Proposed N/A for amd64 only | Review pending |
| CVE-2023-45853 | CRITICAL | app / `zlib1g` | Debian says affected MiniZip code is not built into this Bookworm binary | Not applicable to this binary per Debian | Verify package lineage; obtain independent review | Proposed N/A; no compensating control claimed | Review pending |
| CVE-2023-24538 | CRITICAL | db / `/usr/local/bin/gosu`, Go `stdlib` | Binary-mode `govulncheck` did not report vulnerable `html/template` symbols | Scanner lists Go 1.19.8 / 1.20.3 | Independent review against exact published image | Proposed N/A for this helper; other findings remain | Review pending |
| CVE-2023-24540 | CRITICAL | db / `/usr/local/bin/gosu`, Go `stdlib` | Binary-mode `govulncheck` did not report vulnerable `html/template` symbols | Scanner lists Go 1.19.9 / 1.20.4 | Independent review against exact published image | Proposed N/A for this helper; other findings remain | Review pending |
| CVE-2024-24790 | CRITICAL | db / `/usr/local/bin/gosu`, Go `stdlib` | Binary-mode `govulncheck` did not report vulnerable `net/netip` symbols | Scanner lists Go 1.21.11 / 1.22.4 | Independent review against exact published image | Proposed N/A for this helper; other findings remain | Review pending |
| CVE-2025-68121 | CRITICAL | db / `/usr/local/bin/gosu`, Go `stdlib` | Binary-mode `govulncheck` did not report vulnerable `crypto/tls` symbols | Scanner lists newer Go versions | Independent review against exact published image | Proposed N/A for this helper; other findings remain | Review pending |

## Advisory and source-path triage notes

- [Debian's CVE-2023-45853 record](https://security-tracker.debian.org/tracker/CVE-2023-45853)
  states that the vulnerable MiniZip code was not built into the Bookworm
  `zlib1g` binary at the scanned version. This supports a proposed
  not-applicable disposition for the one zlib finding, pending independent
  security review of the exact image package.
- [CVE-2026-8376](https://security-tracker.debian.org/tracker/CVE-2026-8376)
  concerns a 32-bit Perl build and attacker-controlled regex compilation.
  [PR CI run 35260520816](https://github.com/jybanez/syndicatum/actions/runs/35260520816)
  recorded `amd64` for the tested application image. The four package rows
  are proposed not applicable to that tested image; a wider multi-architecture
  release claim would require separate analysis and independent review.
- [CVE-2026-42496](https://security-tracker.debian.org/tracker/CVE-2026-42496)
  concerns Perl `Archive::Tar` extraction of attacker-controlled symlink
  targets. No direct Perl or `Archive::Tar` call was found in tracked PHP
  application code. [PR CI run 35258886273](https://github.com/jybanez/syndicatum/actions/runs/35258886273)
  also confirms the shipped Apache runtime has no enabled CGI/Perl module or
  `libperl` linkage. Apache's installed Perl dependency alone therefore does
  not show reachability, but indirect CLI/transitive execution paths remain
  to be checked.
- [CVE-2026-13221](https://security-tracker.debian.org/tracker/CVE-2026-13221)
  concerns compilation of a Perl regex with more than 65,535 alternatives.
  No direct application Perl call was found; this is not yet proof of
  unreachability.
- [CVE-2025-7458](https://security-tracker.debian.org/tracker/CVE-2025-7458)
  requires the ability to issue crafted SQLite SQL. Tracked PHP application
  code has no direct SQLite call, but the PHP SQLite extensions are loaded and
  Apache depends on `libsqlite3-0`; runtime exposure remains under review.
- [CVE-2026-6653](https://security-tracker.debian.org/tracker/CVE-2026-6653)
  concerns crafted XML input to libxml2. Tracked PHP application code has no
  direct XML parser call, but PHP XML extensions are loaded; exposure remains
  under review.
- The four database-image Go findings are attributed by the exact CI scan to
  `/usr/local/bin/gosu`, not to `mysqld`. The MySQL entrypoint invokes `gosu`
  to drop privileges before starting the server. The selected `mysql:5.7.44`
  image digest `sha256:4bc6bc963e6d8443453676cae56536f4b8156d78bae03c0145cbe47c2aad73bb`
  contains `gosu` 1.16 built with Go 1.18.2 on amd64; the extracted binary's
  SHA-256 is `3a4e1fc7430f9e7dd7b0cbbe0bfde26bf4a250702e84cf48a1eb2b631c64cf13`.
  A byte-string probe found `text/template` as a positive control but no
  `html/template`, `net/netip`, or `crypto/tls` package paths. This is
  supporting evidence, not a complete call-path analysis. The Go advisories concern
  [`html/template` JavaScript escaping (CVE-2023-24538)](https://pkg.go.dev/vuln/GO-2023-1703),
  [`html/template` whitespace escaping (CVE-2023-24540)](https://pkg.go.dev/vuln/GO-2023-1752),
  [`net/netip` address classification (CVE-2024-24790)](https://pkg.go.dev/vuln/GO-2024-2887),
  and [`crypto/tls` session resumption with mutated configuration (CVE-2025-68121)](https://pkg.go.dev/vuln/GO-2026-4337).
  `govulncheck` v1.7.0 in binary mode against the extracted `gosu` found no
  vulnerable symbols for these four advisories. This supports proposed N/A
  dispositions, pending independent review and repeat on the exact published
  image. The [gosu maintainer's security policy](https://github.com/tianon/gosu/blob/master/SECURITY.md)
  specifically recommends `govulncheck` for this distinction rather than
  assuming every vulnerable Go standard-library package is invoked. The same
  run *did* report ten other symbol-level vulnerabilities, including runc
  library and Go `os/exec` findings. Those require separate applicability
  review; the four proposed N/A rows do not clear the database security gate.
  Reproduction command: `go run golang.org/x/vuln/cmd/govulncheck@v1.7.0 -mode binary /audit/gosu`
  against the extracted binary hash above. Its
  additional open review queue is runc
  `GO-2026-5761`, `GO-2025-4098`, `GO-2024-3110`, `GO-2023-1683`,
  `GO-2023-1682`, `GO-2023-1627`, `GO-2022-0452` and Go standard library
  `GO-2026-4602`, `GO-2025-3956`, `GO-2023-1840`. Binary symbol presence
  does not by itself prove the advisory's exploit conditions in `gosu`.

## HIGH findings and decision policy

The same CI inventory contains 87 HIGH application-image and 97 HIGH
database-image package/CVE findings. Release relevance has not yet been
determined; **none is silently accepted**. Triage each finding against runtime
reachability, a compatible fix, and the selected MySQL baseline. Record every
release-relevant HIGH finding in this table before external production
acceptance. Fix compatible, reachable findings where possible; any remaining
exposed HIGH finding requires documented mitigation and owner/security
acceptance. This is the Commercial Assessor's recommended threshold, not yet an
approved owner exception.

Of the database HIGH rows, the exact CI inventory attributes 57 to the bundled
`gosu` binary, 32 to Oracle Linux packages, and 8 to Python packages. This is
an attribution queue, not a release disposition. The application inventory
previously contained five HIGH rows for the `curl` command-line package and
another five for its shared library. An APT dry run showed the CLI could be
purged without removing the library; the Dockerfile now removes it. CI run
35259335422 passed acceptance and the CLI rows fell to zero, reducing
application HIGH findings from 92 to 87. The five `libcurl4` HIGH findings
remain for applicability/fix review.

| CVE | Severity | Image / package | Reachable or exposed? | Fix available? | Planned action | Mitigation / residual risk | Acceptance |
| --- | --- | --- | --- | --- | --- | --- | --- |
| CVE-2024-21626 | HIGH | db / `gosu` / `github.com/opencontainers/runc` | Advisory concerns `runc` container creation; `gosu` only switches user and execs | runc 1.1.12 listed | Verify affected path is absent from bundled helper | Proposed N/A for `gosu`, not Docker host runtime | Review pending |
| CVE-2023-27561 | HIGH | db / `gosu` / `github.com/opencontainers/runc` | Advisory concerns `runc` container configuration; `gosu` only switches user and execs | runc 1.1.5 listed | Verify affected path is absent from bundled helper | Proposed N/A for `gosu`, not Docker host runtime | Review pending |
| CVE-2025-31133 | HIGH | db / `gosu` / `github.com/opencontainers/runc` | Advisory concerns `runc` rootfs masking; `gosu` does not set up rootfs | runc 1.2.8 listed | Verify affected path is absent from bundled helper | Proposed N/A for `gosu`, not Docker host runtime | Review pending |
| CVE-2025-52565 | HIGH | db / `gosu` / `github.com/opencontainers/runc` | Advisory concerns `runc` console bind mounts; `gosu` does not create mounts | runc 1.2.8 listed | Verify affected path is absent from bundled helper | Proposed N/A for `gosu`, not Docker host runtime | Review pending |
| CVE-2025-52881 | HIGH | db / `gosu` / `github.com/opencontainers/runc` | Advisory concerns `runc` procfs/LSM setup; `gosu` does not configure containers | runc 1.2.8 listed | Verify affected path is absent from bundled helper | Proposed N/A for `gosu`, not Docker host runtime | Review pending |
| CVE-2026-12064 | HIGH (scanner) | app / `libcurl4` | Upstream describes a curl CLI-only path; CLI was purged, but library remains | No Debian fix listed in scan | Independently confirm CLI-only scope and published image contents | Proposed N/A for library; no acceptance yet | Review pending |
| CVE-2026-6276 | HIGH (scanner) | app / `libcurl4` | Requires reuse of one easy handle after a custom Host header; application PHP call sites create and close a handle per request | No Debian fix listed in scan | Verify all reachable call paths and upstream preconditions | Proposed N/A for current app paths; library remains | Review pending |
| CVE-2026-8286 | HIGH (scanner) | app / `libcurl4` | Requires cleartext mail/FTP/LDAP STARTTLS connection reuse; identified PHP call sites use HTTP(S) | No Debian fix listed in scan | Verify configured URL schemes and indirect callers | Proposed N/A for current app paths; library remains | Review pending |
| CVE-2026-8458 | HIGH (scanner) | app / `libcurl4` | Requires HTTP Negotiate service-name use; no `CURLOPT_SERVICE_NAME` found in application source | No Debian fix listed in scan | Verify indirect callers and runtime configuration | Proposed N/A for current app paths; library remains | Review pending |
| CVE-2026-8927 | HIGH (scanner) | app / `libcurl4` | Requires reuse of one handle across different Digest-authenticating proxies; application PHP call sites create and close per request | No Debian fix listed in scan | Verify indirect callers and proxy configuration | Proposed N/A for current app paths; library remains | Review pending |

The [gosu maintainer](https://github.com/tianon/gosu) describes the helper as
switching user/group and then `exec`-ing the target process. The
[runc maintainer advisories](https://github.com/opencontainers/runc/security/advisories)
describe container-creation/configuration paths for the five rows above.
`govulncheck` reported some shared runc-library symbols in `gosu`; that
symbol-level result is not proof that the rootfs/mount/namespace attack paths
are invoked. Independent review must verify each proposed N/A and separately
assess the Docker host runtime version; this table concerns the bundled helper.

The [curl upstream advisories](https://curl.se/docs/security.html) specify the
preconditions for the five `libcurl4` rows. Source inspection covered the
application's PHP cURL call sites in `src/`; it is not yet a complete audit of
extensions, dependencies, deployment proxy settings, or the published image.
Upstream severity can differ from the scanner's HIGH rating. None of these
proposed dispositions changes the release gate before independent review.

The first `v1.0.0-rc.N` may be marked **internal/test only** to validate the
release process, but must not be presented as production-ready while critical
findings are unreviewed. The table is complete only when every CRITICAL and
release-relevant HIGH row has evidence-backed disposition and an identified
accepting authority. Green inventory CI alone does not satisfy this gate.
Before external/design-partner approval, rerun the scan on the exact published
RC artifact/images and reconcile every finding against this table; a branch
candidate's scan is not a substitute. Keep the selected MySQL 5.7.44 baseline
unless a specific applicable, reachable, unmitigated finding forces an owner
decision.
