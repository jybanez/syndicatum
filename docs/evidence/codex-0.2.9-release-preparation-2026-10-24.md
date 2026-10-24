# Codex 0.2.9 release preparation

PR #151 merged as `cf3fdfb538625aa343217a34ff0ae74e0e238b22` after Commercial Assessor approval #11344 and all 11 protected checks passed in workflow `40072628837`. The reviewed head was `fec4dbd7dffbd799daddbf5fdb35607a04d5ee05`.

The change pauses only a route whose destination explicitly rejects enqueue with “no rollout found for thread id.” It retains undelivered state, reports degraded health, and isolates synchronous/asynchronous dispatch failures between routes. Transient failures keep their existing retry behavior. All 96 connector tests pass.

Version 0.2.9 packages that merged implementation without changing runtime code. The existing `codex-v0.2.8` tag remains immutable. No production-support claim or installed acceptance follows from this preparation.

Before installation, publish and verify the reviewed 0.2.9 tag and deterministic release artifacts, add its exact resolved revision to the bounded lifecycle helper, and retain an external verified backup. The standalone transition must stop the connector Scheduled Task and verify its runtime and file locks are released before backup; merely closing Codex does not stop the background listener. Preserve all earlier failed-attempt artifacts. Run Inspect, the rejection fixture, and one Apply using the verified manifest. Restart the listener afterward and verify installed bytes, advancing health, preserved identities/bindings, and delivery to a healthy route while another route is unavailable. An unavailable destination requires the correct device/chat binding; do not redirect it to an arbitrary chat or acknowledge its pending messages.

Publication, bounded installation, rollback, failed-update recovery, revocation, second-device coverage, and live delivery acceptance remain outstanding.
