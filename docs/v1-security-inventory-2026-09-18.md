# V1 source security inventory — 2026-09-18

**Status:** Candidate-branch inventory, not a security sign-off or a release gate.
The public [PR CI run 35252906075](https://github.com/jybanez/syndicatum/actions/runs/35252906075)
scanned PR test commit `519fbeaac07adfafe0c5a350a1bb4bfb12a36152`.
The retained summary reports zero secret findings, zero dependency vulnerability
findings, and four Dockerfile configuration findings. The raw scanner report is
not uploaded; it may contain sensitive matches on future runs.

| File | Finding | Severity | Interpretation |
| --- | --- | --- | --- |
| `Dockerfile` | `DS-0002` | High | No non-root `USER` directive; Apache startup remains privileged. |
| `docker/mysql.Dockerfile` | `DS-0002` | High | No non-root `USER` directive; MySQL image startup remains privileged. |
| `Dockerfile` | `DS-0026` | Low | No image `HEALTHCHECK`; Compose defines service health checks. |
| `docker/mysql.Dockerfile` | `DS-0026` | Low | No image `HEALTHCHECK`; Compose defines a database health check. |

The `DS-0026` results describe the image metadata, not the deployed Compose
health-check behavior. The `DS-0002` results require runtime review rather
than a blanket suppression: the application and database have startup work
that may need privileges, while the worker does not. The candidate Compose
change therefore runs only the worker as `www-data` and adds an acceptance
assertion for its effective UID. The Apache and MySQL findings remain open.

This filesystem scan does not assess built container-image packages, PHP or
JavaScript code vulnerabilities, third-party license obligations, or the
security support status of MySQL 5.7.44. Zero dependency findings in this
source scan must not be interpreted as zero image or runtime vulnerabilities.
The Docker CI candidate now inventories the exact application and database
images built during isolated acceptance. It retains severity counts and a
vulnerability-only package/version/fix/CVE inventory; it requires a
non-empty scanner result for each image and does not fail merely
because a known vulnerability is present. Its first run must be inspected and
triaged before any vulnerability-release policy is claimed.
The first image scan in [PR CI run 35253824628](https://github.com/jybanez/syndicatum/actions/runs/35253824628)
passed technically and reported 17 critical, 286 high, and 1261 medium
application-image findings, plus 4 critical, 97 high, and 88 medium
database-image findings. These are finding counts, not unique CVE counts or
evidence of exploitability. CI now retains a vulnerability-only
package/version/fix/CVE inventory for triage; it still does not upload raw
secret-scan reports.
The retained inventory from [PR CI run 35254483121](https://github.com/jybanez/syndicatum/actions/runs/35254483121)
shows 190 high findings attached to the application image's
`linux-libc-dev` package. That package is used during extension compilation,
not by the intended PHP runtime. The candidate Dockerfile now explicitly
keeps runtime libraries and purges build-only development packages after
compilation. The next Docker acceptance and image scan must show whether this
reduces findings without breaking startup, PHP extensions, or backup/restore.
The first purge passed [PR CI run 35255117748](https://github.com/jybanez/syndicatum/actions/runs/35255117748),
but only reduced the application image to 16 critical and 281 high findings;
`linux-libc-dev` still accounts for 190 high findings. A local APT dry run
showed that purging that package would also remove compiler/header packages,
not the explicitly kept runtime libraries. The candidate purge now includes
`linux-libc-dev`, subject to a fresh Docker acceptance and image scan.
Before an external production-readiness claim, review the built images and
the terminal MySQL baseline, establish a severity policy, and obtain the
planned legal and security review.
