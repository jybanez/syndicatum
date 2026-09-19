# V1 container security acceptance table

**Status:** Exact-RC CRITICAL triage recorded; Commercial Assessor review is
pending. No residual risk is accepted by this table. The HIGH-finding review
and the overall security-disposition gate remain open. This is a release-gate
record, not a claim that package presence proves practical exploitability.
Source secret/dependency scan results are tracked separately in
[`v1-security-inventory-2026-09-18.md`](v1-security-inventory-2026-09-18.md).

**Authoritative RC evidence baseline:** internal/non-production prerelease
[`v1.0.0-rc.1`](https://github.com/jybanez/syndicatum/releases/tag/v1.0.0-rc.1),
protected-main commit
`5e9b4f4161c026cc663abd0b587ea474bf5150de`, archive SHA-256
`57567f70748707d98ad57100e7e1bd81e322f69be7953b8a7f9230fea912ee99`,
and [release run 35424177468](https://github.com/jybanez/syndicatum/actions/runs/35424177468).
The exact archive was independently downloaded and hash-verified, then passed
clean-install, health/recovery, backup, and restore acceptance on MySQL 5.7.44.
The run's Docker acceptance artifact retains
`image-security-findings.tsv` with scanner target, package type, package,
installed version, scanner-listed fix version, CVE, and severity. This table
records all 19 CRITICAL package/CVE rows individually. A blank scanner fix
version means **not listed by Trivy**, not proof that no fix exists. The
dispositions below reconcile the exact RC rows with the published source,
pinned-image contents, official Debian/Go advisories, and the prior binary
analysis. They are proposed technical dispositions pending Commercial Assessor
review; none is an owner acceptance of residual risk.
The earlier process probe in [PR CI run 35261244225](https://github.com/jybanez/syndicatum/actions/runs/35261244225)
recorded one UID 0 Apache master with three effective capabilities. The later
[PR CI run 35264623906](https://github.com/jybanez/syndicatum/actions/runs/35264623906)
passed the archived-candidate lifecycle after Apache moved to internal port
8080 and UID 33 with **all app capabilities dropped**. Its acceptance probe
requires UID 33 Apache processes, zero effective capabilities for Apache and
MySQL, and `NoNewPrivs=1`. The worker service also runs as UID 33. The image
has no enabled Apache CGI/Perl module and no Apache `libperl` linkage. Thus
the Dockerfile `USER` heuristic does not describe MySQL's steady-state server
privilege, and the previous Apache root-master exposure has been removed from
the candidate stack. The Compose
stack uses `no-new-privileges:true`. In `compose.yaml`, the database has no
published host port and attaches only to the `internal: true` backend network;
the application publishes port 80 to host loopback by default (the bind
address is configurable), and the worker publishes no port. The database
volume is writable at `/var/lib/mysql`, and the app/worker share a writable
avatar volume at `/var/lib/syndicatum/avatars`. The app and worker root
filesystems are now read-only; `/tmp` is tmpfs for both, and Apache run/lock
directories are separate tmpfs mounts owned by UID 33. Clean install and
backup/restore passed with these mounts in run 35264623906. The database
volume remains writable as required, while database root-filesystem
hardening and any indirect runtime write paths beyond the exercised
acceptance scenario remain to be reviewed. These changes narrow exposure but
do not accept or close any vulnerability finding.

[PR CI run 35263219092](https://github.com/jybanez/syndicatum/actions/runs/35263219092)
first made the privilege probe fail on regression. The assertion was tightened
for the non-root/zero-capability app model in run 35264623906. This protects
the tested baseline but is not an independent production security review.

## CRITICAL findings

The `Present?` column means the scanner-attributed package or binary is in the
exact RC-built image. It does not mean the vulnerable behavior is on a
supported execution path. Repeated Perl rows are retained because the release
artifact reports them separately even though they share one Debian source
package and one reachability analysis.

| CVE / scanner | Exact RC image path or package | Installed / scanner fix | Present? and advisory condition | Supported-path exposure | Proposed disposition | Evidence and review state |
| --- | --- | --- | --- | --- | --- | --- |
| CVE-2026-13221 / Trivy Debian | app / `libperl5.36` | `5.36.0-7+deb12u3` / none listed | Package present; Debian marks the exact Bookworm package vulnerable even though its tracker notes the upstream vulnerable change was introduced in Perl 5.37.10; condition is compiling a Perl regex trie with more than 65,535 fixed-string branches | No production source invokes Perl; Apache has no CGI/Perl module or `libperl` linkage; no attacker-controlled input reaches Perl regex compilation | `unreachable` | Exact RC source search and release acceptance Perl/CGI probe; Assessor approved in Syndicatum #2851, treating the Debian package as affected despite the upstream-version discrepancy |
| CVE-2026-42496 / Trivy Debian | app / `libperl5.36` | `5.36.0-7+deb12u3` / none listed | Package present; condition is `Archive::Tar` extraction of an attacker-controlled symlink target | No production source invokes Perl or `Archive::Tar`; no supported upload/import path passes an archive to Perl | `unreachable` | Exact RC source search, Apache probe, and Debian advisory; Assessor approved in Syndicatum #2851 |
| CVE-2026-8376 / Trivy Debian | app / `libperl5.36` | `5.36.0-7+deb12u3` / none listed | Package present, but the heap overflow requires a **32-bit** Perl build and attacker-controlled regex compilation | Exact RC app image is `amd64`; the architectural precondition is absent, and Perl is not a supported runtime path | `not_applicable` | Exact RC image architecture plus Debian advisory; Assessor approved in Syndicatum #2851 |
| CVE-2025-7458 / Trivy Debian | app / `libsqlite3-0` | `3.40.1-2+deb12u2` / none listed | Library present; Debian marks Bookworm vulnerable; condition is ability to execute crafted arbitrary SQLite SQL with a very large `ORDER BY` expression list | Dockerfile builds `pdo_mysql`, not SQLite extensions; production source has no SQLite call or SQLite database path; supported storage is MySQL | `unreachable` | Exact RC Dockerfile and production-source search plus Debian advisory; Assessor approved in Syndicatum #2851 |
| CVE-2026-6653 / Trivy Debian | app / `libxml2` | `2.9.14+dfsg-1.3~deb12u6` / none listed | Library present; Debian marks Bookworm vulnerable; condition is crafted XML reaching `xmlParseInternalSubset` entity-resolution handling | Exact RC production source has no XML parser call or XML content-type handling; its Dockerfile enables only Apache `headers` and `rewrite`, its vhost configuration adds no XML/WebDAV handler, and its route inventory exposes no independent XML-facing endpoint | `unreachable` | Exact RC production-source, Dockerfile, `.htaccess`, and `docker/apache-syndicatum.conf` search plus Debian advisory; Assessor approved in Syndicatum #2851 with this runtime-exposure evidence retained |
| CVE-2026-13221 / Trivy Debian | app / `perl` | `5.36.0-7+deb12u3` / none listed | Package present; Debian package is treated as affected despite the upstream-version discrepancy recorded above | Same exact-image analysis: no production Perl execution or attacker-controlled regex compilation path | `unreachable` | Exact RC source/Apache evidence; Assessor approved in Syndicatum #2851 |
| CVE-2026-42496 / Trivy Debian | app / `perl` | `5.36.0-7+deb12u3` / none listed | Package present; same `Archive::Tar` symlink condition above | Same exact-image analysis: no production Perl/archive extraction path | `unreachable` | Exact RC source/Apache evidence; Assessor approved in Syndicatum #2851 |
| CVE-2026-8376 / Trivy Debian | app / `perl` | `5.36.0-7+deb12u3` / none listed | Package present; exploit condition is 32-bit-only | Exact RC is `amd64`; architectural precondition absent | `not_applicable` | Exact RC architecture plus Debian advisory; Assessor review pending |
| CVE-2026-13221 / Trivy Debian | app / `perl-base` | `5.36.0-7+deb12u3` / none listed | Package present; same Perl regex condition above | Same exact-image analysis: no production Perl execution or attacker-controlled regex compilation path | `unreachable` | Exact RC source/Apache evidence; Assessor review pending |
| CVE-2026-42496 / Trivy Debian | app / `perl-base` | `5.36.0-7+deb12u3` / none listed | Package present; same `Archive::Tar` symlink condition above | Same exact-image analysis: no production Perl/archive extraction path | `unreachable` | Exact RC source/Apache evidence; Assessor review pending |
| CVE-2026-8376 / Trivy Debian | app / `perl-base` | `5.36.0-7+deb12u3` / none listed | Package present; exploit condition is 32-bit-only | Exact RC is `amd64`; architectural precondition absent | `not_applicable` | Exact RC architecture plus Debian advisory; Assessor review pending |
| CVE-2026-13221 / Trivy Debian | app / `perl-modules-5.36` | `5.36.0-7+deb12u3` / none listed | Package present; same Perl regex condition above | Same exact-image analysis: no production Perl execution or attacker-controlled regex compilation path | `unreachable` | Exact RC source/Apache evidence; Assessor review pending |
| CVE-2026-42496 / Trivy Debian | app / `perl-modules-5.36` | `5.36.0-7+deb12u3` / none listed | Package present; `Archive::Tar` code is in the installed Perl distribution | No supported production path invokes the module or extracts user-controlled archives through Perl | `unreachable` | Exact RC source/Apache evidence and Debian advisory; Assessor review pending |
| CVE-2026-8376 / Trivy Debian | app / `perl-modules-5.36` | `5.36.0-7+deb12u3` / none listed | Package present; exploit condition is 32-bit-only | Exact RC is `amd64`; architectural precondition absent | `not_applicable` | Exact RC architecture plus Debian advisory; Assessor review pending |
| CVE-2023-45853 / Trivy Debian | app / `zlib1g` | `1:1.2.13.dfsg-1` / none listed | Package present, but Debian states Bookworm's vulnerable MiniZip code was not built into any binary from this source package | Vulnerable code is absent from the exact package lineage; no compensating control is needed for this CVE | `not_applicable` | Debian security tracker package/build record; Assessor review pending |
| CVE-2023-24538 (GO-2023-1703) / Trivy Go binary | db / `/usr/local/bin/gosu` Go `stdlib` | Go `1.18.2` / `1.19.8`, `1.20.3` | `gosu` present; advisory requires `html/template.Template.Execute*`; exact helper binary analysis reports no vulnerable symbols | `gosu` only resolves identity, drops privilege, and execs `mysqld`; it does not render HTML/JavaScript templates | `not_applicable` | Pinned digest, exact `gosu` SHA-256, binary-mode `govulncheck`, Go advisory, and gosu policy; Assessor review pending |
| CVE-2023-24540 (GO-2023-1752) / Trivy Go binary | db / `/usr/local/bin/gosu` Go `stdlib` | Go `1.18.2` / `1.19.9`, `1.20.4` | `gosu` present; advisory requires `html/template.Template.Execute*`; exact helper binary analysis reports no vulnerable symbols | No HTML-template execution exists in the entrypoint privilege-drop helper | `not_applicable` | Pinned digest, exact binary evidence, Go advisory, and gosu policy; Assessor review pending |
| CVE-2024-24790 (GO-2024-2887) / Trivy Go binary | db / `/usr/local/bin/gosu` Go `stdlib` | Go `1.18.2` / `1.21.11`, `1.22.4` | `gosu` present; advisory requires `net/netip.Addr.Is*`; exact helper binary analysis reports no vulnerable symbols | The privilege-drop/exec path performs no IP classification or network authorization | `not_applicable` | Pinned digest, exact binary evidence, Go advisory, and gosu policy; Assessor review pending |
| CVE-2025-68121 (GO-2026-4337) / Trivy Go binary | db / `/usr/local/bin/gosu` Go `stdlib` | Go `1.18.2` / `1.24.13`, `1.25.7`, `1.26.0-rc.3` | `gosu` present; advisory requires TLS session resumption with a mutated `tls.Config`; exact helper binary analysis reports no vulnerable symbols | `gosu` establishes no TLS connection and only drops privilege before `exec` | `not_applicable` | Pinned digest, exact binary evidence, Go advisory, and gosu policy; Assessor review pending |

**Triage result:** no CRITICAL row is currently identified as applicable and
reachable through the supported RC1 deployment path. Ten rows are proposed
`unreachable` and nine are proposed `not_applicable`. This is not a security
sign-off: the Assessor must review these dispositions, the HIGH queue remains
open, and any later change that adds Perl, SQLite, XML, or a different
architecture/image must reopen the affected rows. No
`accepted_residual_risk` disposition is proposed, so no owner risk decision is
requested at this stage.

## Advisory and source-path triage notes

- [Debian's CVE-2023-45853 record](https://security-tracker.debian.org/tracker/CVE-2023-45853)
  states that the vulnerable MiniZip code was not built into the Bookworm
  `zlib1g` binary at the scanned version. The exact RC retains that same
  Bookworm package lineage, supporting `not_applicable` for this row pending
  Assessor review.
- [CVE-2026-8376](https://security-tracker.debian.org/tracker/CVE-2026-8376)
  concerns a 32-bit Perl build and attacker-controlled regex compilation.
  exact RC run 35424177468 recorded `amd64` for the tested application image.
  The four package rows are proposed not applicable to RC1; a wider multi-architecture
  release claim would require separate analysis and independent review.
- [CVE-2026-42496](https://security-tracker.debian.org/tracker/CVE-2026-42496)
  concerns Perl `Archive::Tar` extraction of attacker-controlled symlink
  targets. No direct Perl or `Archive::Tar` call was found in tracked PHP
  application code. [PR CI run 35258886273](https://github.com/jybanez/syndicatum/actions/runs/35258886273)
  first confirmed the shipped Apache runtime has no enabled CGI/Perl module or
  `libperl` linkage; the exact RC acceptance repeats that assertion. Exact RC
  production-source and entrypoint searches also found no indirect Perl or
  `Archive::Tar` execution path. An operator-added extension or future feature
  would reopen the disposition.
- [CVE-2026-13221](https://security-tracker.debian.org/tracker/CVE-2026-13221)
  concerns compilation of a Perl regex with more than 65,535 alternatives.
  The exact RC has no supported Perl execution path and therefore no path for
  remote input to reach that compilation behavior.
- [CVE-2025-7458](https://security-tracker.debian.org/tracker/CVE-2025-7458)
  requires the ability to issue crafted SQLite SQL. The exact RC Dockerfile
  builds `pdo_mysql`, not SQLite extensions; production code has no SQLite call
  or database path, and the supported datastore is MySQL.
- [CVE-2026-6653](https://security-tracker.debian.org/tracker/CVE-2026-6653)
  concerns crafted XML input reaching `xmlParseInternalSubset`. Exact RC
  production code has no XML parser call or supported user-controlled XML
  ingestion path.
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
  vulnerable symbols for these four advisories. RC1 pins the identical MySQL
  digest and locally reverified the same `gosu` version, architecture, and
  binary SHA-256, so the prior binary result applies to the exact RC base
  image without substituting a different helper. This supports proposed N/A
  dispositions pending Assessor review. The [gosu maintainer's security policy](https://github.com/tianon/gosu/blob/master/SECURITY.md)
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

The tracked production PHP search found seven direct cURL callers:
`AccountIntegration`, `AgentWebhookWorker`, `GoogleIntegration`,
`IntegrationHealth`, `RealtimeIntegration`, `ResponsesApiActivationService`,
and `WorkspaceAgentTriggerService`. Each creates a new easy handle and closes
it after one transfer, including the two background delivery callers. No
`curl_multi`, shared-handle, handle-reset/copy, `CURLOPT_SERVICE_NAME`,
`CURLOPT_PROXYAUTH`, or explicit proxy option appears in tracked production
PHP; the bundled `curl` CLI is absent. Several callers explicitly disable
redirects, while the others leave libcurl's default redirect behavior. The
Compose service environment does not configure proxy variables. These are
negative *source and declared-Compose* findings, not proof that an operator's
modified environment, extension, or future integration cannot invoke a
vulnerable path. Confirm the exact published image and runtime environment
before an independent reviewer accepts any N/A disposition.

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
The exact published RC archive and its built images were scanned in run
35424177468 and reconciled above; a branch candidate is not being substituted.
Before external/design-partner approval, complete the prioritized HIGH review
and obtain the required security/owner dispositions. Keep the selected MySQL
5.7.44 baseline unless a specific applicable, reachable, unmitigated finding
forces an owner decision.
