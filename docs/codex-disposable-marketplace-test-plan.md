# Disposable Windows marketplace semantics plan

DESIGN ONLY. Implements the constraints in Syndicatum 11482. Provisioning and
execution require recorded owner approval/designation of the specific environment.
No test is authorized on HomeServer or another production Codex profile.

## Admission checks before any CLI command

- Disposable Windows VM/Sandbox or equivalently isolated host, with no production
  profiles, credentials, bindings, registry/marketplace/plugin state, host mounts,
  shared clipboard, drive redirection or production-directory access.
- Exact privately transferred CLI: 0.163.0-alpha.10.2; SHA-256
  `f6dbf48cb70f62649c645f26c87dc9bf84cc36986202dfad8e12fb5b8ca70312`.
  Record binary hash/version; never upload the binary to public storage.
- All configuration, home, app data, credentials store and cache roots resolve
  inside disposable guest storage. No real account login or API keys.
- Outbound denial enforced at guest/environment boundary before CLI execution,
  including child processes, IPv4/IPv6, DNS and proxies. Proxy variables alone
  are not network isolation. Record enforcement policy and independent deny-test
  result plus connection/audit evidence; do not infer denial from no observed traffic.
- Inert fixture only: marketplace `pilot-fixture-market`, plugin `pilot-fixture`,
  a minimal compatibility manifest, and harmless marker text. No MCP, apps, hooks,
  executable scripts, Git/npm/remote sources or actual Syndicatum package.
- Keep fixtures on guest-local paths outside any real installation. Explicitly
  select the synthetic marketplace identity; never use broad upgrade/remove.

## Evidence capture

Before each action retain fixture-only tree inventories (relative paths, sizes,
hashes and mtimes), guest profile/config/marketplace/cache snapshots, network
policy state, command classification, binary hash, exit status and bounded output.
Raw guest-only details remain private. Publish sanitized identity/relative-path
conclusions, not environment values or command output that could reveal secrets.
Unexpected real profile/config discovery => STOP without invoking any lifecycle
command. Evidence must record its own capture failures separately from CLI results.

## Minimum matrix

Each row is a separate named fixture case, not a blind retry of a failed command.
Record pre/post evidence for each case and all errors; do not force an outcome.

1. Add the synthetic local marketplace directory with `plugin marketplace add`.
   Observe returned root and configuration reference/copy effects.
2. Re-add the exact same path/name. Observe already-added versus replacement.
3. Change only a harmless fixture marker, then re-add the same path/name. Measure
   whether marketplace registration and managed content changed.
4. Add the inert plugin with its explicit synthetic marketplace key. Observe
   cache location and verify every installed fixture file against source bytes.
5. Re-add the same plugin identity with unchanged bytes; record result and cache.
6. Change marker under the same identity/version and add it again. Observe
   replacement/error/cache/old-version cleanup effects without force flags.
7. Change source marker without running an update; compare marketplace root and
   installed plugin cache independently (reference semantics and copy semantics
   may differ). Do not activate the plugin or open an agent session.
8. Create a second guest-local fixture directory with the same marketplace name;
   attempt its addition to capture different-source conflict. Do not remove the
   original registration to bypass rejection.

Stop the case on unexpected lifecycle execution, attempted network activity,
unexpected identity, writes outside guest-local boundaries, ambiguous outcome,
or evidence capture failure. Do not repeat failed operations automatically.
No account binding, notification, registry startup modification or real plugin
activation is part of this test.

## Exit gate

Retain a complete result bundle before any guest destruction. Record which
semantics were observed and which remain unproven, including binary/source
correspondence and internal cache rollback/cleanup behavior. Have Assessor review
the results before coding a live installation adapter. Disposable cleanup needs
separate authorization and must not erase the only evidence copy.

Passing these fixtures provides product semantics only, never HomeServer migration
or installation authority. Live backup/task/Run/process/state changes remain HOLD.
