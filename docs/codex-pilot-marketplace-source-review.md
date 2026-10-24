# Local-marketplace source review (not installed acceptance)

Status: source evidence only. No CLI add/remove/update or candidate activation
has been performed. This does not establish binary-to-source equivalence or
permit changing HomeServer's existing marketplace configuration.

## Reviewed upstream

HomeServer reports CLI `0.163.0-alpha.10.2`, executable SHA-256
`f6dbf48cb70f62649c645f26c87dc9bf84cc36986202dfad8e12fb5b8ca70312`.
The official `openai/codex` repository's matching version tag
`rust-v0.163.0-alpha.10.2` resolved during review to
`d9151c94a70013f176ff370fa93da02734662e41`. Source review used that immutable
object, not current main. Reproducible binary correspondence is not proven.

Reviewed files at that object:

- `codex-rs/core-plugins/src/marketplace_add.rs`:
  `add_marketplace_sync_with_cloner` checks an already configured identical
  source first; local-path branch returns that absolute local root directly.
  A marketplace with the same name from another source returns an explicit
  remove-before-add conflict. The local branch returns before Git staging/cloning.
- Its tests `add_marketplace_sync_installs_local_directory_source_and_updates_config`
  and `add_marketplace_sync_treats_existing_local_directory_source_as_already_added`
  assert these outcomes and use a panic Git-cloner callback for local sources.
  These are inspected upstream tests, NOT tests executed in this workspace.
- `codex-rs/cli/src/plugin_cmd.rs`: non-global plugin addition calls the local
  manager's `install_plugin`; the global remote branch uses remote operations.
  Context building still loads configuration and an auth manager; branch inspection
  alone does not establish complete credential isolation or absence of telemetry.
- `codex-rs/core-plugins/src/manager.rs`: `install_plugin` resolves the selected
  plugin and policy before `install_resolved_plugin`. There is a distinct
  `install_plugin_with_remote_sync` method; do not conflate those entry points.
- `codex-rs/core-plugins/src/store.rs`: plugin materialization recursively copies
  regular files to staged version storage and activates cache content by rename.
  Existing-version replacement can back up the previous cache and internally
  restore it on activation error; new-version activation can remove old versions.
  These effects require explicit review against preservation/no-rollback policy.

## Decisions not established by the evidence

1. A local marketplace root is referenced in place, but a materialized plugin is
   copied to cache. These are different objects; do not call both copied/referenced.
2. Same-name Git-to-local marketplace addition is not a supported overwrite under
   the inspected code. Do not add undocumented force flags or hand-edit config.
3. The local branch does not call its Git cloner, but this is NOT a proof of no
   network activity throughout CLI config/auth/metrics startup or plugin install.
4. The final cache manifest/version, same-plugin refresh and cleanup outcomes
   must still be measured in a safely isolated profile before live use.
5. The CLI's internal transactional cache restoration differs from an outer
   migration retry/rollback. Neither may be silently assumed approved.

## Bounded next facts / review

Obtain only the current marketplace's sanitized source type/name and whether an
enforced managed source applies; do not disclose paths or configuration contents.
If switching requires removal of an existing marketplace, that is a new explicit
mechanism review gate, not covered by approval to remove the Windows Run value.
Do not test this conflict against the real profile.

Before a disposable-profile probe, prove alternate profile roots cover all CLI
configuration, auth/credential store, installed cache and state, and establish
outbound denial/capture. Use neutral synthetic plugins without MCP/hooks and no
production credentials. No service/VM startup is inferred from source-review scope.
