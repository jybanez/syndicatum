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
Before an external production-readiness claim, review the built images and
the terminal MySQL baseline, establish a severity policy, and obtain the
planned legal and security review.
