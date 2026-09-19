# V1 external host and runtime baseline

Status: **automated preflight implemented; a passing real external-host evidence
bundle is still required before external-deployment approval**.

This baseline defines the minimum host posture for a future design-partner V1
deployment. It does not change the approved `v1.0.0-rc.1` boundary: RC1 remains
internal/non-production only, and the overall security-disposition gate remains
open.

## Reference environment

The immutable RC1 acceptance ran on the GitHub `ubuntu-24.04` amd64 runner with:

- Docker Engine `28.0.4`;
- Docker Compose `v2.38.2`;
- default OCI runtime `runc` `1.5.1`;
- the pinned PHP 8.2/Apache Bookworm and MySQL 5.7.44 image digests recorded in
  the [security acceptance table](v1-security-acceptance-table.md).

That run is reproducible release evidence, not permission to deploy externally.

## Proposed minimum external host

An external V1 host must satisfy all of the following before installation:

1. **Platform:** 64-bit amd64 Linux on an OS release that is still receiving
   vendor security updates. Ubuntu 24.04 LTS is the reference platform. Custom,
   derivative, or end-of-life distributions and kernels are outside support.
2. **Docker source:** Docker Engine and its plugins come from Docker's official
   stable repository, not the convenience script or an untracked distribution
   fork. Docker documents its supported Linux platforms and bundles
   `containerd`/`runc` through `containerd.io`.
3. **Security floor:** Docker Engine `29.5.1` or later on a maintained stable
   line, because 29.3.1 fixes the AuthZ-plugin bypass and 29.5.1 fixes the later
   `docker cp`/archive host-root and host-filesystem issues. Do not downgrade
   below this floor without explicit security review. The actual candidate host
   must rerun `scripts/docker-acceptance.ps1`; version comparison alone is not
   acceptance.
4. **Compose reference:** Compose `2.38.2` is the version exercised by immutable
   RC1 CI, not a security minimum. Use the maintained Compose v2 plugin bundled
   for the selected Docker release and require the rendered-configuration and
   lifecycle acceptance checks to pass.
5. **OCI runtime:** `runc` `1.3.6` or later on a maintained release line. RC1 CI
   used `1.5.1`. This host-runtime rule is separate from the older runc library
   statically linked into the MySQL image's `gosu` helper.
6. **containerd:** use the Docker-bundled `containerd.io` package on an upstream
   maintained branch. As of 2026-09-19, containerd 1.6 and 2.1 are EOL, and the
   1.7 extension is narrowly maintained for specific GKE releases; a new
   standalone design-partner host should use a currently maintained 2.x line.
   The preflight currently treats 2.2.0 as the minimum maintained standalone
   line. This is a **support-lifecycle floor**, not an independently claimed
   CVE fix threshold or a permanently frozen product version; it must move when
   the upstream lifecycle changes. Do not install conflicting standalone
   `containerd` or `runc` packages beside Docker's bundle.
7. **Kernel and patching:** use the vendor kernel for the supported OS, apply
   security updates before onboarding, enable unattended security updates or a
   documented monthly patch window, and rerun acceptance after Docker,
   containerd, runc, or kernel upgrades.
8. **Network boundary:** publish only the HTTPS reverse-proxy endpoint. Do not
   publish MySQL. Restrict administrative access to the designated operator
   network, and put explicit host policy in the `DOCKER-USER` chain because
   Docker-published ports can bypass common host-firewall expectations.
9. **Privilege boundary:** only designated administrators may access the Docker
   socket or Docker group. Retain the Compose non-root, zero-capability, and
   `no-new-privileges` assertions exercised by acceptance.
10. **Operations:** require UTC time synchronization, monitored disk capacity,
   encrypted host storage, off-host encrypted backups, and a successful restore
   exercise before external activation.

Primary references:

- [Docker Engine Ubuntu installation and supported-platform requirements](https://docs.docker.com/engine/install/ubuntu/)
- [Docker Engine installation channels and upgrade policy](https://docs.docker.com/engine/install/)
- [Docker Engine 29 security release notes](https://docs.docker.com/engine/release-notes/29/)
- [Docker Engine 29.5.1 archive-upload advisory](https://github.com/moby/moby/security/advisories/GHSA-x86f-5xw2-fm2r)
- [containerd release lifecycle](https://github.com/containerd/containerd/blob/main/RELEASES.md)
- [runc releases](https://github.com/opencontainers/runc/releases)

## MySQL 5.7 boundary

MySQL 5.7.44 remains the exact functional-compatibility baseline for RC1, but it
is not an acceptable normal external-production security baseline. Oracle moved
MySQL 5.7 to Sustaining Support on 2023-10-25, identifies 5.7.44 as the final
5.7 release, and recommends upgrade. The pinned image also carries an aged OS
and userland package set with a large unresolved HIGH queue.

Therefore:

- internal RC validation may continue on pinned MySQL 5.7.44;
- no design-partner, external-production, or GA claim should rely on that image
  without an explicit, time-bounded owner/security exception;
- the recommended default gate is compatibility and migration acceptance on a
  currently supported MySQL LTS baseline, presently MySQL 8.4 LTS, followed by
  a fresh immutable-candidate scan and published-byte clean-install,
  backup/restore, upgrade, and rollback evidence;
- if commercial timing requires a temporary 5.7 exception, it must state the
  exposure boundary, compensating controls, expiry date, named owner, and exit
  migration. This document does not grant that exception.

Primary references:

- [MySQL product support EOL announcements](https://www.mysql.com/support/eol-notice.html)
- [MySQL supported platforms and current LTS lines](https://www.mysql.com/support/supportedplatforms/database.html)
- [MySQL 5.7.44 final-release note](https://dev.mysql.com/doc/relnotes/mysql/5.7/en/news-5-7-44.html)

## Required preflight evidence

Before any external host is accepted, retain:

```text
uname -a
cat /etc/os-release
docker version
docker compose version
docker info
runc --version
containerd --version
```

The evidence record must also include host patch date, firewall rules, open
ports, Docker-socket administrators, backup target, and the exact successful
acceptance run. Run the fail-closed collector on the candidate host after these
controls are configured:

```bash
sudo ./scripts/external-host-preflight.sh \
  --expected-docker-admins 'operator1,operator2' \
  --host-patch-date 'YYYY-MM-DD' \
  --operator-network '203.0.113.0/24' \
  --encrypted-storage-evidence 'ticket-or-command-output-reference' \
  --disk-monitor-evidence 'monitor-or-alert-reference' \
  --backup-target 's3://encrypted-off-host-target' \
  --backup-verified-at 'YYYY-MM-DD' \
  --acceptance-run-id 'exact archived-candidate run reference'
```

The script requires root so firewall and package-source evidence is complete.
It automatically enforces Ubuntu 24.04 amd64, a recognized Ubuntu vendor
kernel, Docker Engine >=29.5.1 from Docker's official repository, maintained
Compose v2, `runc` >=1.3.6, a currently maintained containerd 2.x line via
`containerd.io`, no conflicting standalone runtime packages, an explicit
`DOCKER-USER` policy, no non-loopback listeners on HTTP/Docker-API/MySQL ports,
an exact declared Docker-group member set, synchronized time, and recent patch
and restore dates. The Compose major is a supported interface requirement;
Compose 2.38.2 remains the tested RC1 reference rather than a separate security
floor. The containerd 2.2.0 threshold is the current upstream-maintenance floor
described above.

Storage encryption, disk monitoring, operator-network scope, off-host backup,
and the exact archived-candidate acceptance reference are operator-supplied
attestations. The collector requires and records them but does not pretend to
independently prove the named external systems.

Each run writes raw command output, a pass/fail ledger, operator facts, and a
SHA-256 manifest into a timestamped evidence directory. A passing bundle is
necessary but not sufficient: it must be reviewed together with the exact
successful archived-candidate lifecycle on that host. The `--self-test` mode
used by CI validates only the script contract and never constitutes host
acceptance evidence. Unsupported distributions, architectures, containerd
branches, local-only backup targets, unrecognized kernels, or undocumented
exceptions fail closed and require a separately reviewed baseline change; the
script has no bypass flag.
