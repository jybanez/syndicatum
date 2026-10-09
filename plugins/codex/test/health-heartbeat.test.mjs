import assert from "node:assert/strict";
import test from "node:test";
import { isHealthFresh, startHealthHeartbeat } from "../mcp/health-heartbeat.mjs";

test("background health freshness is bounded by timestamp age", () => {
  const now = Date.parse("2026-10-10T00:00:00.000Z");
  assert.equal(isHealthFresh({ updatedAt: "2026-10-09T23:59:30.000Z" }, now), true);
  assert.equal(isHealthFresh({ updatedAt: "2026-10-09T23:58:00.000Z" }, now), false);
  assert.equal(isHealthFresh({ updatedAt: "invalid" }, now), false);
});

test("background health heartbeat writes fresh snapshots and stops cleanly", async () => {
  let revision = 0;
  const written = [];
  const heartbeat = startHealthHeartbeat({
    intervalMs: 1_000,
    snapshot: () => ({ revision: ++revision }),
    write: async value => { written.push(value); },
  });

  await heartbeat.tick();
  await heartbeat.tick();
  await heartbeat.stop();
  const stoppedCount = written.length;
  await new Promise(resolve => setTimeout(resolve, 20));

  assert.ok(stoppedCount >= 2);
  assert.deepEqual(written.map(item => item.revision), Array.from({ length: stoppedCount }, (_, index) => index + 1));
  assert.equal(written.length, stoppedCount);
});

test("background health heartbeat does not overlap slow writes", async () => {
  let active = 0;
  let maximum = 0;
  const heartbeat = startHealthHeartbeat({
    intervalMs: 5,
    snapshot: () => ({}),
    write: async () => {
      active += 1;
      maximum = Math.max(maximum, active);
      await new Promise(resolve => setTimeout(resolve, 20));
      active -= 1;
    },
  });

  await new Promise(resolve => setTimeout(resolve, 45));
  await heartbeat.stop();
  assert.equal(maximum, 1);
});

test("background health heartbeat reports a failed write and keeps running", async () => {
  let attempts = 0;
  const errors = [];
  const heartbeat = startHealthHeartbeat({
    intervalMs: 10,
    snapshot: () => ({}),
    write: async () => {
      attempts += 1;
      if (attempts === 1) throw new Error("disk busy");
    },
    onError: error => { errors.push(error.message); },
  });

  const deadline = Date.now() + 500;
  while (attempts < 2 && Date.now() < deadline) {
    await new Promise(resolve => setTimeout(resolve, 10));
  }
  await heartbeat.stop();
  assert.deepEqual(errors, ["disk busy"]);
  assert.ok(attempts >= 2);
});
