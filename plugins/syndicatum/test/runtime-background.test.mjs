import assert from "node:assert/strict";
import { access, mkdir, mkdtemp, writeFile } from "node:fs/promises";
import os from "node:os";
import path from "node:path";
import test from "node:test";
import { PluginRuntime } from "../mcp/runtime.mjs";

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
  assert.equal(status.state, "running");
  await assert.rejects(access(path.join(root, "listener.lock")), { code: "ENOENT" });
  runtime.configWatcher?.close();
});
