import assert from "node:assert/strict";
import { access, mkdir, mkdtemp, writeFile } from "node:fs/promises";
import os from "node:os";
import path from "node:path";
import test from "node:test";
import { PluginRuntime, selectAvailableBindings } from "../mcp/runtime.mjs";

test("device binding validation tolerates missing directory hints and rejects missing discussions", async () => {
  const calls = [];
  const source = [
    { project_id: 1, agent_id: 10, conversation_id: "thread-ok", working_directory: "C:\\valid" },
    { project_id: 1, agent_id: 11, conversation_id: "thread-stale", working_directory: "C:\\missing" },
    { project_id: 1, agent_id: 12, conversation_id: "", working_directory: "C:\\valid" },
  ];
  const result = await selectAvailableBindings(source, async directory => {
    calls.push(directory);
    if (/missing$/i.test(directory)) throw new Error("missing");
  });
  assert.equal(result.validBindings.length, 2);
  assert.equal(result.validBindings[1].working_directory, null);
  assert.equal(result.unavailableBindings.length, 1);
  assert.equal(source[0].working_directory, "C:\\valid");
  assert.equal(calls.length, 2);
});

test("MCP runtime leaves listener ownership with a healthy background service", async () => {
  const localAppData = await mkdtemp(path.join(os.tmpdir(), "syndicatum-runtime-"));
  const root = path.join(localAppData, "Syndicatum", "CodexPlugin");
  await mkdir(root, { recursive: true });
  await writeFile(path.join(root, "connector.config.json"), JSON.stringify({
    mode: "device",
    syndicatumUrl: "https://syndicatum.example",
    deviceId: "device-test",
  }), "utf8");

  let ensureCalls = 0;
  const runtime = new PluginRuntime({ LOCALAPPDATA: localAppData, SYNDICATUM_AGENT_TOKEN: "test-token" }, {
    background: {
      async ensureRunning() {
        ensureCalls += 1;
        return { supported: true, installed: true, running: true, ownsListener: true, pid: 42 };
      },
    },
  });

  const status = await runtime.start();
  assert.equal(ensureCalls, 1);
  assert.equal(status.role, "background");
  assert.equal(status.state, "ready");
  await assert.rejects(access(path.join(root, "listener.lock")), { code: "ENOENT" });
  runtime.configWatcher?.close();
});

test("connector status is rebuilt from persisted configuration instead of stale MCP memory", async () => {
  const localAppData = await mkdtemp(path.join(os.tmpdir(), "syndicatum-runtime-status-"));
  const root = path.join(localAppData, "Syndicatum", "CodexPlugin"); await mkdir(root, { recursive: true });
  await writeFile(path.join(root, "connector.config.json"), JSON.stringify({ mode: "device", syndicatumUrl: "https://syndicatum.example", deviceId: "device-ready" }), "utf8");
  const runtime = new PluginRuntime({ LOCALAPPDATA: localAppData, SYNDICATUM_AGENT_TOKEN: "test-token" }, { background: { async status() { return { supported: true, running: true, ownsListener: true, pid: 91, listenerPid: 91, readiness: "ready" }; } } });
  runtime.status = { state: "unconfigured" };
  const status = await runtime.currentStatus();
  assert.equal(status.state, "ready"); assert.equal(status.deviceId, "device-ready"); assert.equal(status.background.pid, 91);
});
