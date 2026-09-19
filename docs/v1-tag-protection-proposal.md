# V1 release tag protection — approval proposal

**Status:** Approved by Jonathan in Syndicatum message 2369 and applied on
2026-09-18. Both active rulesets were read back from GitHub. No V1 tag has been
created.
**Repository:** `jybanez/syndicatum`
**Prior observation:** The repository rulesets API returned zero rulesets
before this change. Protected `main` and the RC workflow alone did not prevent
a release tag from being moved or deleted.

## Recommended boundary

Protect `refs/tags/v1.*`, covering `v1.0.0-rc.N`, stable `v1.0.0`, and later
V1 tags. Existing `companion-v*` package tags are outside this scope.

The two **active tag rulesets** use this same ref pattern:

1. **V1 tag creation:** `creation` rule; only repository owner `jybanez`
   (GitHub user ID `309048`) has an `always` bypass to create a matching tag.
   No other writer or administrator is granted a creation bypass. The creator
   must create a new annotated tag from a reviewed commit on protected `main`.
2. **V1 tag immutability:** `update` and `deletion` rules; **empty bypass list**.
   No owner, administrator, application, or automation receives permission to
   move or delete an existing V1 tag through normal GitHub ref operations.

Separate rulesets matter: granting the owner a creation bypass in a single
combined ruleset would also bypass its update/deletion restrictions. Repository
administrators can still edit or disable repository rulesets as a governance
action; this policy does not claim that account owners are technically unable
to change the policy itself.

Approved rule bodies (the GitHub-created names use ASCII hyphens):

```json
{
  "name": "V1 tag creation - owner only",
  "target": "tag",
  "enforcement": "active",
  "bypass_actors": [
    {"actor_id": 309048, "actor_type": "User", "bypass_mode": "always"}
  ],
  "conditions": {"ref_name": {"include": ["refs/tags/v1.*"], "exclude": []}},
  "rules": [{"type": "creation"}]
}
```

```json
{
  "name": "V1 tag immutability - no bypass",
  "target": "tag",
  "enforcement": "active",
  "bypass_actors": [],
  "conditions": {"ref_name": {"include": ["refs/tags/v1.*"], "exclude": []}},
  "rules": [{"type": "update"}, {"type": "deletion"}]
}
```

## Verification before the first RC

1. Read back both rulesets from GitHub and confirm `target=tag`,
   `enforcement=active`, the exact ref pattern, rule types, and bypass lists.
   Do not silently substitute a broader admin bypass if GitHub rejects the
   proposed owner-only creation rule; return for a policy decision instead.
2. Merge the reviewed release-candidate branch through protected `main`, with
   its required `source-contract` and `docker-source-acceptance` checks passing
   for that exact commit. Include release notes for the candidate tag.
3. Have the authorized creator make a new annotated `v1.0.0-rc.N` tag pointing
   to that reviewed `main` commit. Do not reuse or move a tag.
4. The tag-triggered workflow verifies the annotated ref and its commit's
   presence on `main`, reruns both required jobs at the tagged revision,
   publishes the SHA-256 archive/provenance as a prerelease, downloads it, and
   performs isolated clean-install/backup/restore acceptance on MySQL 5.7.44
   strict mode.
5. Read back the tag target and release assets after the run, retain the run
   URL/logs/hash, and have the Commercial Assessor review the complete evidence
   chain. A failed RC is superseded by a **new** RC number; never repair it by
   moving the old tag.

Readback on 2026-09-18 confirmed:

- ruleset `23612219`: active `tag` target, `refs/tags/v1.*`, `creation`, sole
  bypass `User:309048:always`;
- ruleset `23612208`: active `tag` target, `refs/tags/v1.*`, `update` and
  `deletion`, empty bypass list.

This closes tag-protection setup, not the published-RC release gate. Recheck
these rulesets immediately before the first tag is created.

## GitHub references

- [Available branch and tag rules](https://docs.github.com/en/repositories/configuring-branches-and-merges-in-your-repository/managing-rulesets/available-rules-for-rulesets)
- [Repository rulesets REST API](https://docs.github.com/en/rest/repos/rules)
