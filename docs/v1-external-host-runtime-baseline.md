# V1 external host and runtime baseline

Status: **proposed for security review; not yet an external-deployment approval**.

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
3. **Validated floor:** Docker Engine `28.0.4` or later and Compose `2.38.2` or
   later. The actual candidate host must rerun `scripts/docker-acceptance.ps1`;
   version comparison alone is not acceptance.
4. **OCI runtime:** `runc` `1.3.6` or later on a maintained release line. RC1 CI
   used `1.5.1`. This host-runtime rule is separate from the older runc library
   statically linked into the MySQL image's `gosu` helper.
5. **containerd:** use the Docker-bundled `containerd.io` package on an upstream
   maintained branch. As of 2026-09-19, containerd 1.6 and 2.1 are EOL, and the
   1.7 extension is narrowly maintained for specific GKE releases; a new
   standalone design-partner host should use a currently maintained 2.x line.
   Do not install conflicting standalone `containerd` or `runc` packages beside
   Docker's bundle.
6. **Kernel and patching:** use the vendor kernel for the supported OS, apply
   security updates before onboarding, enable unattended security updates or a
   documented monthly patch window, and rerun acceptance after Docker,
   containerd, runc, or kernel upgrades.
7. **Network boundary:** publish only the HTTPS reverse-proxy endpoint. Do not
   publish MySQL. Restrict administrative access to the designated operator
   network, and put explicit host policy in the `DOCKER-USER` chain because
   Docker-published ports can bypass common host-firewall expectations.
8. **Privilege boundary:** only designated administrators may access the Docker
   socket or Docker group. Retain the Compose non-root, zero-capability, and
   `no-new-privileges` assertions exercised by acceptance.
9. **Operations:** require UTC time synchronization, monitored disk capacity,
   encrypted host storage, off-host encrypted backups, and a successful restore
   exercise before external activation.

Primary references:

- [Docker Engine Ubuntu installation and supported-platform requirements](https://docs.docker.com/engine/install/ubuntu/)
- [Docker Engine installation channels and upgrade policy](https://docs.docker.com/engine/install/)
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
acceptance run. A future automated preflight should enforce these requirements;
until it exists, this is a manual release checklist item.
