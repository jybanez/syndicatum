# V1 container security acceptance table

**Status:** Triage in progress; no critical finding below is accepted or
dispositioned. This table is a release-gate record, not a claim of practical
exploitability. Source secret/dependency scan results are tracked separately in
[`v1-security-inventory-2026-09-18.md`](v1-security-inventory-2026-09-18.md).

**Evidence baseline:** [PR CI run 35255907969](https://github.com/jybanez/syndicatum/actions/runs/35255907969)
on the archived MySQL 5.7.44 candidate. The Docker acceptance artifact retains
`image-security-findings.tsv` with package, installed version, scanner-listed
fix version, CVE, and severity. This table records its 19 CRITICAL package/CVE
findings individually. A blank scanner fix version means **not listed by the
scanner**, not proof that no fix exists. All applicability and residual-risk
judgments remain open until checked against the running image and advisory.

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
| CVE-2023-45853 | CRITICAL | app / `zlib1g` | Unknown | None listed | Check linked runtime use and advisory applicability | Unassessed | Open |
| CVE-2023-24538 | CRITICAL | db / Go `stdlib` | Unknown | Scanner lists Go 1.19.8 / 1.20.3 | Identify bundled binary and supported rebuild path | Legacy-image risk unassessed | Open |
| CVE-2023-24540 | CRITICAL | db / Go `stdlib` | Unknown | Scanner lists Go 1.19.9 / 1.20.4 | Identify bundled binary and supported rebuild path | Legacy-image risk unassessed | Open |
| CVE-2024-24790 | CRITICAL | db / Go `stdlib` | Unknown | Scanner lists Go 1.21.11 / 1.22.4 | Identify bundled binary and supported rebuild path | Legacy-image risk unassessed | Open |
| CVE-2025-68121 | CRITICAL | db / Go `stdlib` | Unknown | Scanner lists newer Go versions | Identify bundled binary and supported rebuild path | Legacy-image risk unassessed | Open |

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
