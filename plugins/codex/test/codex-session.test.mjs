import assert from "node:assert/strict";
import test from "node:test";
import { selectCodexOwner, liveCodexOwners, watchCodexSession, readProcessTable } from "../mcp/codex-session.mjs";
import { CodexDriver } from "../mcp/codex-driver.mjs";

const cli = { pid: 20, parentPid: 30, executable: "C:\\Users\\Example\\Codex\\bin\\codex.exe", startedAt: "cli-start" };
const desktop = { pid: 30, parentPid: 1, executable: "C:\\Program Files\\WindowsApps\\OpenAI.Codex_1.0_x64\\app\\ChatGPT.exe", startedAt: "desktop-start" };
const host = { pid: 10, parentPid: 20, executable: "C:\\node.exe", startedAt: "host-start" };

test("Codex Desktop lifetime takes precedence over an on-demand MCP or CLI host", () => {
  assert.equal(selectCodexOwner([host, cli, desktop], 10).pid, 30);
  assert.equal(selectCodexOwner([host, { ...cli, parentPid: 1 }], 10).pid, 20);
  assert.equal(selectCodexOwner([host, { ...cli, executable: "C:\\other.exe" }], 10), null);
  assert.equal(selectCodexOwner([host, { ...cli, parentPid: 10, executable: "C:\\other.exe" }], 10), null);
});

test("a shared listener remains owned until the last session exits, without accepting PID reuse", () => {
  const other = { ...desktop, pid: 31, startedAt: "second-start" };
  const owners = [selectCodexOwner([host, cli, desktop], 10), { pid: other.pid, startedAt: other.startedAt, executable: other.executable }];
  assert.equal(liveCodexOwners(owners, [other]).length, 1);
  assert.equal(liveCodexOwners(owners, []).length, 0);
  assert.equal(liveCodexOwners(owners, [{ ...desktop, startedAt: "reused-pid" }]).length, 0);
});

test("the watchdog closes once, and a concurrent new owner can defer closure", async () => {
  let open = true, closes = 0, defer = true;
  const watch = watchCodexSession({ isOpen: async () => open, onClosed: async () => {
    if (defer) { defer = false; return false; }
    closes += 1;
  }, intervalMs: 60000 });
  try {
    await watch.tick();
    assert.equal(closes, 0);
    open = false;
    await watch.tick();
    assert.equal(closes, 0);
    await watch.tick();
    await watch.tick();
    assert.equal(closes, 1);
  } finally { watch.stop(); }
});

test("failure to establish owner liveness closes the listener", async () => {
  let closes = 0;
  const watch = watchCodexSession({ isOpen: async () => { throw new Error("process inspection failed"); }, onClosed: async () => { closes += 1; }, intervalMs: 60000 });
  try { await watch.tick(); assert.equal(closes, 1); } finally { watch.stop(); }
});

test("Windows owner inspection requests identities without process arguments", async () => {
  let command;
  const records = await readProcessTable("win32", async (_exe, args) => { command = args.at(-1); return { stdout: JSON.stringify([host, cli, desktop]) }; });
  assert.equal(records.length, 3);
  assert.doesNotMatch(command, /CommandLine/);
  assert.match(command, /CreationDate/);
});

test("notification delivery cannot reopen a closed Codex session", async () => {
  let open = false, queued = 0, loaded = 0;
  const driver = new CodexDriver({ codexPath: process.execPath, codexThreadId: "thread", activationAllowed: async () => open }, async () => {
    queued += 1; open = false; return { stdout: "queued message", stderr: "" };
  }, async () => { loaded += 1; });
  await assert.rejects(driver.activate({ id: 1 }), { code: "CODEX_SESSION_CLOSED" });
  assert.equal(queued, 0);
  open = true;
  await assert.rejects(driver.activate({ id: 1 }), { code: "CODEX_SESSION_CLOSED" });
  assert.equal(queued, 1);
  assert.equal(loaded, 0);
});
