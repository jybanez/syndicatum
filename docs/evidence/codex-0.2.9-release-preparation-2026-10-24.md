# Codex 0.2.9 release preparation

PR #151 merged as `cf3fdfb538625aa343217a34ff0ae74e0e238b22` after Commercial Assessor approval #11344 and all 11 protected checks passed in workflow `40072628837`. The reviewed head was `fec4dbd7dffbd799daddbf5fdb35607a04d5ee05`.

The change pauses only a route whose destination explicitly rejects enqueue with “no rollout found for thread id.” It retains undelivered state, reports degraded health, and isolates synchronous/asynchronous dispatch failures between routes. Transient failures keep their existing retry behavior.

Test history: one full plugin-suite invocation during 0.2.9 preparation reported 95/96 passing. The failing-case detail was not retained, so its cause remains unexplained. Two subsequent full runs passed 96/96, including one that explicitly checked the test process exit code. The `source-contract` check on source head `4e9364fc8df4368e52a8b3607bd257d0f36219f9` passed in workflow `40081718192`; all 11 checks on that head subsequently completed successfully. These successful runs do not erase or explain the earlier anomalous result. CI must also pass on the superseding documentation-correction head before merge/publication.

Version 0.2.9 packages that merged implementation without changing runtime code. The existing `codex-v0.2.8` tag remains immutable. No production-support claim or installed acceptance follows from this preparation.

Before installation, publish and verify the reviewed 0.2.9 tag and deterministic release artifacts, add its exact resolved revision to the bounded lifecycle helper, and retain an external verified backup. The standalone transition must stop the connector Scheduled Task and verify its runtime and file locks are released before backup; merely closing Codex does not stop the background listener. Preserve all earlier failed-attempt artifacts. Run Inspect, the rejection fixture, and one Apply using the verified manifest. Restart the listener afterward and verify installed bytes, advancing health, preserved identities/bindings, and delivery to a healthy route while another route is unavailable. An unavailable destination requires the correct device/chat binding; do not redirect it to an arbitrary chat or acknowledge its pending messages.

Publication, bounded installation, rollback, failed-update recovery, revocation, second-device coverage, and live delivery acceptance remain outstanding.
