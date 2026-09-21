# V1 live-source reconciliation inventory

**Status:** Working inventory, based on the serving checkout before the
security-containment change. This is not authorization to replace the serving
tree. See [the legacy uplift proposal](v1-legacy-live-uplift-proposal.md) for
the database and acceptance boundary.

The serving checkout had 40 dirty paths: 25 modified tracked files and 15
untracked paths. Of the 25 modified tracked files, two already have exactly the
protected-main file content; the other 23 differ from protected main. This
inventory classifies content, not just Git status. Changes introduced during
this investigation (the live `.htaccess` containment rule and the separate
review-branch commits) are outside the original 40-path count.

## Tracked files

| Path | Classification against protected main | Integration disposition |
| --- | --- | --- |
| `assets/app.css` | Intentional live unified workspace/timeline and collapse UX; overlaps newer V1 surfaces | Integrate live layout with main's admin/recovery styling; do not replace wholesale. |
| `assets/app.mjs` | Intentional live workspace/timeline behavior; main has Backup / Restore, current auth and responsibility | Merge by surface and test both behavior sets. |
| `companion/README.md` | Modified relative to old live HEAD, but byte-identical to main | Main already contains it. |
| `companion/extension/background.mjs` | Live version lacks main's 0.10.1 binding/polling recovery | Keep main; do not regress Companion. |
| `companion/extension/manifest.json` | Modified relative to old live HEAD, but byte-identical to main | Main already contains it. |
| `companion/test/package.test.mjs` | Superseded by main's newer Companion tests | Keep main. |
| `docs/application-surfaces.md` | Intentional live unified workspace/timeline description; main adds V1 admin/recovery description | Reconcile to describe integrated UI. |
| `docs/docker-deployment.md` | Main has newer release/backup boundary | Keep main; separately review any live-only operational note before discarding. |
| `docs/pbb-chat-log-skill-replacement.md` | Live uses a 50-message example; main uses 100 | Carry the accurate default-page example without narrowing the allowed explicit limit. |
| `docs/syndicatum-expansion-implementation-checklist.md` | Historical checklist diverged on both sides | Review evidence/status lines individually; do not wholesale replace main. |
| `docs/timeline-collapse-expand-proposal.md` | Live-only interaction proposal | Review and retain in top-level `/docs` alongside integrated implementation. |
| `docs/v1-commercial-viability-implementation-checklist.md` | Main has substantially newer V1 gate evidence | Keep main as authoritative; review any live-only historical facts. |
| `docs/v1-release-policy.md` | Live adds six lines requiring supported host runtime/security review | Carry forward after reconciling with current release policy. |
| `docs/v1-security-acceptance-table.md` | Main records later exact-RC triage; live retains older open-triage status | Keep main; do not reopen closed findings by copying old status. |
| `index.php` | Intentional live unified three-column workspace/timeline shell; main has newer admin and recovery mounts | Integrate both shells and route targets. |
| `skills/syndicatum/references/protocol-v1.md` | Main describes newer numeric-ID and idempotency contract | Keep main. |
| `src/AdminService.php` | Main adds username support and participant status-generation updates | Keep main. |
| `src/AuthService.php` | Main adds username login/registration and response fields | Keep main. |
| `tests/expansion.php` | Main has newer responsibility/expansion coverage | Keep main. |
| `tests/google-sso.php` | Main has newer identity assertions | Keep main. |
| `tests/registration.php` | Main has username registration coverage | Keep main. |
| `tests/surfaces.php` | Live asserts unified workspace/timeline; main asserts V1 admin/recovery | Integrate both sets, updating selectors and human workflow tests. |
| `vendor/pbb-helper/dist/helpers.ui.bundle.min.css` | Live Helper build differs from main | Carry only as part of a reviewed complete Helper revision set. |
| `vendor/pbb-helper/dist/helpers.ui.bundle.min.js` | Live Helper build differs from main | Carry only as part of a reviewed complete Helper revision set. |
| `vendor/pbb-helper/js/ui/ui.loader.js` | Live selects newer icon/nav/splitter/timeline bundle revisions | Integrate with matching library files, licenses, and component tests. |

## Untracked paths in the serving checkout

| Path | Classification | Disposition |
| --- | --- | --- |
| `.well-known/openai-apps-challenge` | Instance-local verification material | Preserve on serving host; never publish in source or package. |
| `companion/test/authorization-poll.test.mjs` | Untracked in live, but tracked on main with different content | Keep main's newer authorization/poll contract; retain only distinct valid live cases. |
| `docs/companion-v0.10.0-release-notes.md` | Historical release note | Archive/review; do not substitute for 0.10.1 release state. |
| `docs/plugin-publication-checklist.md` | Draft documentation | Review before top-level `/docs` publication; not runtime code. |
| `docs/plugin-submission-draft.md` | Draft documentation | Review before top-level `/docs` publication; not runtime code. |
| `docs/v1-canonical-package-installer-backup-restore-proposal.md` | Owner-approved architecture proposal | Preserve and review for publication in top-level `/docs`. |
| `docs/v1-host-runtime-security-review-2026-09-18.md` | Host-runtime assessment | Review currency and evidence before publication; no automatic release claim. |
| `docs/v1-package-installer-admin-ui-proposal.md` | Owner-approved UI proposal | Preserve and review for publication in top-level `/docs`. |
| `output/` | Generated packages/mockups and previously a historical SQL dump | Exclude from package/source. Historical SQL was moved off-webroot with hash preserved. Apache denial belongs to the separate security-containment track, not this source-only integration. |
| `tests/__pycache__/` | Generated cache | Exclude. |
| `vendor/pbb-helper/docs/` | Helper icon-pack provenance/documentation | Review with complete Helper revision; preserve required attribution. |
| `vendor/pbb-helper/js/ui/ui.icons.ai.js` | Helper component asset | Integrate only with matching loader/bundle. |
| `vendor/pbb-helper/js/ui/ui.icons.files.js` | Helper component asset | Integrate only with matching loader/bundle. |
| `vendor/pbb-helper/js/ui/ui.icons.social.js` | Helper component asset | Integrate only with matching loader/bundle. |
| `vendor/pbb-helper/licenses/` | Third-party Helper icon licenses | Preserve with any integrated icon-pack code. |

## Integrated UI ownership map

| Surface | Intended source of truth after integration |
| --- | --- |
| Workspace/project shell and timeline/discussion layout | Live unified layout, adapted to current main contracts. |
| Collapse/expand | Live interaction and its Helper timeline dependency. |
| Admin navigation and Backup / Restore | Main V1 feature and authorization, fitted into the unified shell. |
| Auth/login/bootstrap | Main V1 behavior and validation. |
| Responsibility/inbox | Main V1 data/authorization behavior, fitted into the unified shell. |
| Settings/Audit/Users | Main V1 functions with navigation and responsive integration. |
| Helper | One coherent reviewed revision set; no mixed loader/bundle/component versions. |

Before review or PR, the integrated candidate needs Helper-focused desktop and
mobile checks for navigation, focus/keyboard behavior, collapse state,
accessibility, Backup / Restore visibility, and no regression of the current
workspace/timeline experience. The full-stack disposable clone must then prove
existing account and project data remains usable.
