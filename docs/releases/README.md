# V1 release-candidate notes

The `V1 release candidate` workflow accepts an annotated `v1.0.0-rc.N` tag
whose commit is on protected `main`. It reruns source-contract and isolated
MySQL 5.7.44 Docker acceptance on that tagged commit, then publishes the
**same** archive and SHA-256 manifest retained by the Docker job. It also
publishes a provenance file naming the tag, commit, CI run, and archive hash,
and downloads the release assets to verify the published checksum. It then
performs a second isolated clean-install/backup/restore lifecycle from that
downloaded published archive and retains the result as CI evidence.

Before creating a tag, review and merge the candidate through the protected
pull-request path. Add `docs/releases/<tag>.md` to that reviewed commit, with:

- the exact application tag/commit and candidate status (not production approval);
- the supported clean-install-only Docker baseline and installation procedure;
- the migration head, configuration/secret changes, backup/restore notes, and
  known limitations;
- exact Codex plugin and Companion package versions accepted as installed
  clients, or an explicit statement that installed-client acceptance is open;
- the contract revision and the state of security, legal, and other release
  gates; and
- a link to the CI run after it completes, or the provenance asset that carries
  that run identity.

Do not tag a moving feature branch, reuse or move an RC tag, or use an RC as a
stable release. The workflow rejects a non-annotated tag, a commit outside
`main`, missing release notes, or an existing release. A GitHub tag-protection
ruleset must also be verified to enforce non-movement at the repository
boundary; workflow checks do not themselves make refs immutable. The approved
[V1 tag rulesets](../v1-tag-protection-proposal.md) were applied and read back
on 2026-09-18; recheck them before creating an RC tag.
The first `v1.0.0`
support promise is a fresh Docker installation; migration of the internal
deployment is separate assistance, not an in-place upgrade gate. Stable
`v1.0.0` promotion has its own release review and artifact identity.
