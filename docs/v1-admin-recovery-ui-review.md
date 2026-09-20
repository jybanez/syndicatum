# V1 admin recovery UI review

This is a disposable, UI-only review recipe for the V1 Backup / Restore administration surface. It does not create a backup, inspect a real backup, stage a restore, or touch a live restore target.

## Exact revision

Use the full corrective commit SHA supplied in the Developer's Syndicatum handoff. Check out that immutable revision in a disposable clone or worktree and verify it before starting:

```powershell
$reviewRevision = '<full corrective commit SHA from the handoff>'
git fetch origin
git switch --detach $reviewRevision
if ((git rev-parse HEAD) -ne $reviewRevision) { throw 'The recovery UI review revision does not match.' }
```

Do not review a moving branch name. The fixture and route mock in this revision are part of the review evidence.

## Disposable admin access

Use a disposable database and an administrator created through the existing bootstrap flow. Supply a locally chosen password through the environment; never put it in Git, a screenshot, a browser recording, or the Syndicatum timeline.

```powershell
$reviewPassword = Read-Host 'Disposable administrator password' -AsSecureString
$env:SYNDICATUM_BOOTSTRAP_PASSWORD = [Net.NetworkCredential]::new('', $reviewPassword).Password
php scripts/chat-db.php migrate
php scripts/chat-db.php bootstrap-admin recovery-review@example.test 'Recovery UI Reviewer'
Remove-Item Env:SYNDICATUM_BOOTSTRAP_PASSWORD
$reviewPassword = $null
```

Start the application using the repository's normal local Apache/PHP setup, sign in as that disposable administrator, and open `/backup-restore`. Delete the disposable database or review environment after the review.

## Safe inspection fixture

Use [`tests/fixtures/recovery-ui-review.syndicatum-backup`](../tests/fixtures/recovery-ui-review.syndicatum-backup). It is intentionally not a real encrypted backup and contains no credentials or user data. It is safe only because the inspection request is intercepted by the review mock below.

With an already-open, signed-in Playwright CLI session named `recovery-review`, install the two local response routes and reload the surface:

```powershell
npx --yes --package @playwright/cli playwright-cli -s=recovery-review run-code --filename tests/fixtures/recovery-ui-review-mock.js
npx --yes --package @playwright/cli playwright-cli -s=recovery-review reload
```

The mock enables the Restore action and returns inert inspection metadata after an intentional 450 ms delay. Do not submit the final staged-restore form. No route is provided for `staged-restores.php`, so any such request is a test failure.

## Acceptance checks

1. Open **Restore**, select the safe fixture, and choose **Authenticate and inspect**. The inspection modal should close and **Stage verified restore** should open.
2. Check both acknowledgements, enter `WRONG`, and submit. The confirmation field must receive focus, be marked invalid, show associated exact-format help/error text, remain editable, and send no staged-restore request.
3. Replace the value with `STAGE RESTORE`. The stale field error must clear immediately. Do not submit.
4. Repeat inspection, then close the inspection modal during the mock's 450 ms delay. No staging confirmation may open after dismissal.
5. Repeat the validation checks at desktop size and `390 x 844`, including keyboard-only operation and focus visibility.
6. Exercise an injected inspection failure and verify the existing draggable error alert and retry/close behavior remain available.

The browser network log should contain no request to `api/v1/admin/staged-restores.php` during this review.
