# Developer CLI Git migration source evidence

Read-only review, not executed install acceptance or permission to mutate.
Active target is solely the Developer host under owner 11522 / Commercial 11530.

## Exact version and evidence boundary

Local CLI reports `0.164.0-alpha.11.1`, SHA-256
`1103e9e09c71fa2cd0afd6d8f3b9a2d63174fe8efd7cb5bd09f1cb14ef58a098`.
Official OpenAI tag `rust-v0.164.0-alpha.11.1` is annotated tag object
`52039d642ad91058ecbb229e6c9f553438179728`, dereferencing to source commit
`6905ecede7816e91373e9eabda3f6b0368fc747b`.
This version match is not reproducible binary/source equivalence. CLI help/list
were executed locally; add/remove/install and the upstream tests were not run.

## Source-reviewed effects at that immutable upstream commit

- `codex-rs/core-plugins/src/marketplace_remove.rs`: validates marketplace name;
  rejects another enabled configuration layer referencing that name; requires
  exact configured name casing; removes the base-user marketplace entry then
  its managed snapshot root. Config removal can succeed before snapshot removal
  fails: a failed exit is an uncertain/partial outcome, not proof of no change.
  It does not uninstall plugins or remove the plugin cache in this function.
- `codex-rs/core-plugins/src/marketplace_add/source.rs`: explicit `--ref` takes
  precedence over a suffix ref and is passed to Git source staging.
- `codex-rs/core-plugins/src/marketplace_add/install.rs`: Git cloning followed
  by `git checkout <ref>` supports the intended exact commit argument; sparse
  checkout has a separate clone path. Verify final revision independently.
- `codex-rs/core-plugins/src/marketplace_add.rs`: already-added detection uses
  source metadata, and occupied same-name destinations from another source are
  rejected. Add stages and validates before registration; internal guards can
  clean staging and undo its own activation on failure. Do not retry on failure.
- `codex-rs/core-plugins/src/marketplace_staging.rs`: add performs shared stale
  staging reclamation (up to four qualifying abandoned directories), not only
  target-specific writes. Local shared staging was empty at inspection. Require
  it empty immediately before add and no concurrent marketplace operation;
  unexpected entries block the action rather than authorizing their cleanup.
- `codex-rs/cli/src/plugin_cmd.rs`: explicit configured non-global plugin
  selection calls the manager's `install_plugin`, a distinct remote-sync path.
- `codex-rs/core-plugins/src/store.rs`: copies source regular files into staged
  plugin cache and renames to activate. New-version install can remove old
  versions of that target plugin. Existing-version replacement may internally
  restore its previous cache on activation failure. This is native transactional
  behavior; outer retries/rollback remain prohibited. Back up target first and
  review these effects explicitly rather than promise no internal cleanup.

Source links use the official `openai/codex` repository at the immutable commit
above, under `https://github.com/openai/codex/blob/6905ecede7816e91373e9eabda3f6b0368fc747b/`.

## Proposed exact native operations for later execution review

Use the verified executable, base user configuration and neutral working
directory without project configuration overrides. Resolve the effective config
layers first; stop on another layer or requirements override for `syndicatum`.

1. `plugin marketplace remove syndicatum --json`
2. Independently verify exact marketplace absence and preserved plugin/unrelated
   config/cache inventory; do not infer this from exit code alone.
3. With shared staging still empty and no concurrent operation:
   `plugin marketplace add https://github.com/jybanez/syndicatum.git --ref 9141daa08d896f2524be30b96d01ab2a14398757 --sparse .agents/plugins --sparse plugins/codex --json`
4. Verify configured exact source/ref and managed Git HEAD plus package bytes.
5. `plugin add codex@syndicatum --json`
6. Verify target cache inventory/version and all unrelated configuration/cache
   entries before any owner reopen or plugin runtime activation.

These are review inputs, not a runnable migration script. Effective config-layer
proof, exact external backup/preimage manifests and final execution review remain
required. Source review does not prove empirical installed behavior. No deferred
disposable runner or other-machine approval is reused to fill that gap.
