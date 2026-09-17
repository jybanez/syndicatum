# V1 container security acceptance table

**Status:** Triage in progress; no critical finding below is accepted or
dispositioned. This table is a release-gate record, not a claim of practical
exploitability. Source secret/dependency scan results are tracked separately in
[`v1-security-inventory-2026-09-18.md`](v1-security-inventory-2026-09-18.md).

**Evidence baseline:** [PR CI run 35255907969](https://github.com/jybanez/syndicatum/actions/runs/35255907969)
on the archived MySQL 5.7.44 candidate. The Docker acceptance artifact retains
`image-security-findings.tsv` with package, installed version, scanner-listed
fix version, CVE, and severity. The next CI revision also records the scanner
target path and package type so bundled-binary findings can be attributed.
This table records its 19 CRITICAL package/CVE
findings individually. A blank scanner fix version means **not listed by the
scanner**, not proof that no fix exists. All applicability and residual-risk
judgments remain open until checked against the running image and advisory.
The process probe passed in [PR CI run 35257011669](https://github.com/jybanez/syndicatum/actions/runs/35257011669):
Apache had one UID 0 master and UID 33 workers; MySQL's running server was
UID 999, and the worker service was separately verified as non-root UID 33.
Thus the Dockerfile `USER` heuristic does not describe MySQL's steady-state
server privilege, but the Apache master does remain privileged. The Compose
stack uses `no-new-privileges:true`. In `compose.yaml`, the database has no
published host port and attaches only to the `internal: true` backend network;
the application publishes port 80 to host loopback by default (the bind
address is configurable), and the worker publishes no port. The database
volume is writable at `/var/lib/mysql`, and the app/worker share a writable
avatar volume at `/var/lib/syndicatum/avatars`. No explicit capability drop or
read-only root filesystem is configured. Effective capabilities and whether
the Apache root master is necessary throughout steady state remain open.

## CRITICAL findings

| CVE | Severity | Image / package | Reachable or exposed? | Fix available? | Planned action | Mitigation / residual risk | Acceptance |
| --- | --- | --- | --- | --- | --- | --- | --- |
| CVE-2026-13221 | CRITICAL | app / `libperl5.36` | Unknown | None listed | Check Apache dependency and advisory path | Unassessed | Open |
| CVE-2026-42496 | CRITICAL | app / `libperl5.36` | Unknown | None listed | Check Apache dependency and advisory path | Unassessed | Open |
| CVE-2026-8376 | CRITICAL | app / `libperl5.36` | Unknown | None listed | Check Apache dependency and advisory path | Unassessed | Open |
| CVE-2025-7458 | CRITICAL | app / `libsqlite3-0` | Unknown | None listed | Check whether production PHP/Apache calls SQLite | Unassessed | Open |
| CVE-2026-6653 | CRITICAL | app / `libxml2` | Unknown | None listed | Check XML use and reachable input path | Unassessed | Open |
| CVE-2026-13221 | CRITICAL | app / `perl` | Unknown | None listed | Check Apache dependency and advisory path | Unassessed | Open |
| CVE-2026-42496 | CRITICAL | app / `perl` | Unknown | None listed | Check Apache dependency and advisory path | Unassessed | Open |
| CVE-2026-8376 | CRITICAL | app / `perl` | Unknown | None listed | Check Apache dependency and advisory path | Unassessed | Open |
| CVE-2026-13221 | CRITICAL | app / `perl-base` | Unknown | None listed | Check Apache dependency and advisory path | Unassessed | Open |
| CVE-2026-42496 | CRITICAL | app / `perl-base` | Unknown | None listed | Check Apache dependency and advisory path | Unassessed | Open |
| CVE-2026-8376 | CRITICAL | app / `perl-base` | Unknown | None listed | Check Apache dependency and advisory path | Unassessed | Open |
| CVE-2026-13221 | CRITICAL | app / `perl-modules-5.36` | Unknown | None listed | Check Apache dependency and advisory path | Unassessed | Open |
| CVE-2026-42496 | CRITICAL | app / `perl-modules-5.36` | Unknown | None listed | Check Apache dependency and advisory path | Unassessed | Open |
| CVE-2026-8376 | CRITICAL | app / `perl-modules-5.36` | Unknown | None listed | Check Apache dependency and advisory path | Unassessed | Open |
| CVE-2023-45853 | CRITICAL | app / `zlib1g` | Debian says affected MiniZip code is not built into this Bookworm binary | Not applicable to this binary per Debian | Verify package lineage; obtain independent review | Proposed N/A; no compensating control claimed | Review pending |
| CVE-2023-24538 | CRITICAL | db / `/usr/local/bin/gosu`, Go `stdlib` | Startup helper; vulnerable `html/template` symbols not yet checked in exact CI binary | Scanner lists Go 1.19.8 / 1.20.3 | Check binary symbols/call path; rebuild helper if reachable | Legacy-image risk unassessed | Open |
| CVE-2023-24540 | CRITICAL | db / `/usr/local/bin/gosu`, Go `stdlib` | Startup helper; vulnerable `html/template` symbols not yet checked in exact CI binary | Scanner lists Go 1.19.9 / 1.20.4 | Check binary symbols/call path; rebuild helper if reachable | Legacy-image risk unassessed | Open |
| CVE-2024-24790 | CRITICAL | db / `/usr/local/bin/gosu`, Go `stdlib` | Startup helper; vulnerable `net/netip` symbols not yet checked in exact CI binary | Scanner lists Go 1.21.11 / 1.22.4 | Check binary symbols/call path; rebuild helper if reachable | Legacy-image risk unassessed | Open |
| CVE-2025-68121 | CRITICAL | db / `/usr/local/bin/gosu`, Go `stdlib` | Startup helper; vulnerable `crypto/tls` symbols not yet checked in exact CI binary | Scanner lists newer Go versions | Check binary symbols/call path; rebuild helper if reachable | Legacy-image risk unassessed | Open |

## Advisory and source-path triage notes

- [Debian's CVE-2023-45853 record](https://security-tracker.debian.org/tracker/CVE-2023-45853)
  states that the vulnerable MiniZip code was not built into the Bookworm
  `zlib1g` binary at the scanned version. This supports a proposed
  not-applicable disposition for the one zlib finding, pending independent
  security review of the exact image package.
- [CVE-2026-8376](https://security-tracker.debian.org/tracker/CVE-2026-8376)
  concerns a 32-bit Perl build and attacker-controlled regex compilation.
  Architecture and reachable runtime Perl calls must be verified before the
  four package rows can be marked not applicable.
- [CVE-2026-42496](https://security-tracker.debian.org/tracker/CVE-2026-42496)
  concerns Perl `Archive::Tar` extraction of attacker-controlled symlink
  targets. No direct Perl or `Archive::Tar` call was found in tracked PHP
  application code; Apache's installed Perl dependency and other runtime
  paths remain to be checked.
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
  to drop privileges before starting the server. The Go advisories concern
  [`html/template` JavaScript escaping (CVE-2023-24538)](https://pkg.go.dev/vuln/GO-2023-1703),
  [`html/template` whitespace escaping (CVE-2023-24540)](https://pkg.go.dev/vuln/GO-2023-1752),
  [`net/netip` address classification (CVE-2024-24790)](https://pkg.go.dev/vuln/GO-2024-2887),
  and [`crypto/tls` session resumption with mutated configuration (CVE-2025-68121)](https://pkg.go.dev/vuln/GO-2026-4337).
  These are provisionally unlikely in a user-switch-and-exec helper, but
  binary symbol/call-path evidence from the exact CI image is needed before
  marking any of them not applicable.

## HIGH findings and decision policy

The same CI inventory contains 92 HIGH application-image and 97 HIGH
database-image package/CVE findings. Release relevance has not yet been
determined; **none is silently accepted**. Triage each finding against runtime
reachability, a compatible fix, and the selected MySQL baseline. Record every
release-relevant HIGH finding in this table before external production
acceptance. Fix compatible, reachable findings where possible; any remaining
exposed HIGH finding requires documented mitigation and owner/security
acceptance. This is the Commercial Assessor's recommended threshold, not yet an
approved owner exception.

The first `v1.0.0-rc.N` may be marked **internal/test only** to validate the
release process, but must not be presented as production-ready while critical
findings are unreviewed. The table is complete only when every CRITICAL and
release-relevant HIGH row has evidence-backed disposition and an identified
accepting authority. Green inventory CI alone does not satisfy this gate.
