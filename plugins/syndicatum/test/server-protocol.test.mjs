import assert from "node:assert/strict";
import { spawn } from "node:child_process";
import { once } from "node:events";
import { mkdtemp } from "node:fs/promises";
import os from "node:os";
import path from "node:path";
import test from "node:test";
import { fileURLToPath } from "node:url";

test("MCP server initializes and exposes connector tools while unconfigured", async () => {
  const data = await mkdtemp(path.join(os.tmpdir(), "syndicatum-plugin-test-"));
  const child = spawn(process.execPath, [fileURLToPath(new URL("../mcp/server.mjs", import.meta.url))], {
    env: { ...process.env, SYNDICATUM_PLUGIN_DATA: data, SYNDICATUM_AGENT_TOKEN: "" },
    stdio: ["pipe", "pipe", "pipe"],
  });
  const replies = [];
  let buffer = "";
  let wake;
  child.stdout.setEncoding("utf8");
  child.stdout.on("data", chunk => {
    buffer += chunk;
    const lines = buffer.split(/\r?\n/); buffer = lines.pop() || "";
    for (const line of lines) if (line) replies.push(JSON.parse(line));
    wake?.();
  });
  child.stdin.write(`${JSON.stringify({ jsonrpc: "2.0", id: 1, method: "initialize", params: { protocolVersion: "2024-11-05" } })}\n`);
  child.stdin.write(`${JSON.stringify({ jsonrpc: "2.0", id: 2, method: "tools/list", params: {} })}\n`);
  await Promise.race([
    new Promise(resolve => { const check = () => replies.length >= 2 ? resolve() : (wake = check); check(); }),
    new Promise((_, reject) => setTimeout(() => reject(new Error("MCP server did not reply in time")), 2000)),
  ]);
  assert.equal(replies.find(item => item.id === 1)?.result?.serverInfo?.name, "syndicatum-connector");
  assert.deepEqual(replies.find(item => item.id === 2)?.result?.tools?.map(tool => tool.name), [
    "connector_begin_login",
    "connector_complete_login",
    "connector_status",
    "connector_restart",
    "connector_background_status",
    "connector_background_install",
    "connector_link_discussion",
    "connector_configure_agent",
  ]);
  child.kill(); await once(child, "close");
});
