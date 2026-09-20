# V1 owner test handoff

This is the short acceptance path for the canonical
`v1.0.0-rc.2` prerelease. Test it only in a fresh isolated environment. Do not
point it at the current internal deployment or a production database.

## 1. Download the canonical package

Open the GitHub prerelease:

`https://github.com/jybanez/syndicatum/releases/tag/v1.0.0-rc.2`

Download all four files:

- `syndicatum-v1.0.0.zip`
- `syndicatum-v1.0.0.manifest.json`
- `syndicatum-v1.0.0.sha256`
- `syndicatum-v1.0.0.provenance.json`

Confirm that the provenance contains `"canonical": true`, the manifest names
tag `v1.0.0-rc.2`, and the manifest source commit matches the protected-main
commit reported by the release workflow.

## 2. Verify the checksum

On Linux/macOS, with the ZIP and checksum file in the same directory:

```console
sha256sum --check syndicatum-v1.0.0.sha256
```

On PowerShell:

```powershell
$expected = ((Get-Content .\syndicatum-v1.0.0.sha256 -Raw).Trim() -split '\s+')[0].ToLowerInvariant()
$actual = (Get-FileHash .\syndicatum-v1.0.0.zip -Algorithm SHA256).Hash.ToLowerInvariant()
if ($actual -ne $expected) { throw "Syndicatum package checksum mismatch" }
```

Do not extract or run a package whose checksum does not match.

## 3. Fresh installation

1. Extract the verified ZIP into a new empty directory using a maintained ZIP
   reader. The package contains `app`, `schema`, `plugins`, `skills`, and
   metadata roots.
2. Enter the extracted `app` directory.
3. Copy `.env.example` to `.env` and supply fresh, distinct secrets. Set:
   - `SYNDICATUM_PACKAGE_SHA256` to the verified ZIP SHA-256;
   - `SYNDICATUM_RELEASE_SOURCE_COMMIT` to the full manifest source commit;
   - `SYNDICATUM_BACKUP_KEY_FILE` to a private host file containing a fresh
     base64-encoded 32-byte backup key.
4. Validate and start the isolated stack:

   ```console
   docker compose config --quiet
   docker compose build --pull
   docker compose up -d
   docker compose ps
   ```

5. Confirm installation identity and the baseline-only state:

   ```console
   docker compose exec app php scripts/chat-db.php installation-status
   docker compose exec app php scripts/chat-db.php migration-status
   ```

   Expected: installation state `ready`, the manifest package/source
   identities, baseline `syndicatum-mysql84-1.0.0-baseline.1`, and zero
   historical migration rows.

## 4. First administrator

Fresh startup installs the trusted baseline automatically; it does not replay
historical migrations. Bootstrap the first administrator from the app
container without placing the password in a command argument:

```console
docker compose exec app sh
read -rsp "Bootstrap password: " SYNDICATUM_BOOTSTRAP_PASSWORD; export SYNDICATUM_BOOTSTRAP_PASSWORD; echo
php scripts/chat-db.php bootstrap-admin admin@example.com "Administrator Name"
unset SYNDICATUM_BOOTSTRAP_PASSWORD
exit
```

Open the configured site, sign in, and confirm the administrator navigation
includes **Backup / Restore**. Configure the public HTTPS origin before testing
external OAuth, MCP, or connector binding.

## 5. Personal smoke-test checklist

- [ ] Installation identity shows the exact RC2 package SHA, source commit,
      MySQL 8.4 baseline, and zero historical migrations.
- [ ] Administrator login works and the password can be changed.
- [ ] Create a workspace/project and reload its public project URL.
- [ ] Add or invite a human participant with the intended role; verify a
      cross-project user cannot see the project.
- [ ] Add/claim an agent, address it in one canonical message, and verify one
      reply with the expected sender, addressees, reply link, and project
      sequence.
- [ ] Create and download one encrypted backup; retain its digest and audit
      receipt.
- [ ] Inspect that backup and stage it only into a separately configured empty
      target. Confirm the result says ready/staged and not live, with
      `live_overwrite=false`, `automatic_cutover=false`, and
      `cutover_performed=false`.
- [ ] Confirm no DNS, proxy, domain, serving database, or traffic switch was
      attempted. Any later production cutover is an administrator/hosting
      procedure outside Syndicatum V1.
- [ ] Check `/api/v1/health.php`, container health, and worker operational
      status after the smoke test.

Record the release URL, package SHA-256, source commit, UTC test time, platform,
browser, Docker/Compose versions, and pass/fail notes. Send any release-blocking
failure before using the candidate beyond the isolated owner test.
