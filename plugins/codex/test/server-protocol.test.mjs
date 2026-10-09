import assert from "node:assert/strict";
import { spawn } from "node:child_process";
import { once } from "node:events";
import { mkdtemp, readFile } from "node:fs/promises";
import os from "node:os";
import path from "node:path";
import test from "node:test";
import { fileURLToPath } from "node:url";

test("MCP discovery does not block on background listener startup", async () => {
  const source = await readFile(fileURLToPath(new URL("../mcp/server.mjs", import.meta.url)), "utf8");
  assert.doesNotMatch(source, /const runtime = new PluginRuntime\(\);\s*await\s+runtime\.start\(\)/);
  assert.match(source, /void\s+runtime\.start\(\)\.catch/);
});

test("MCP server initializes and exposes connector tools while unconfigured", async () => {
  const manifest = JSON.parse(await readFile(fileURLToPath(new URL("../.codex-plugin/plugin.json", import.meta.url)), "utf8"));
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
  assert.equal(replies.find(item => item.id === 1)?.result?.serverInfo?.version, manifest.version);
  const discoveredTools = replies.find(item => item.id === 2)?.result?.tools || [];
  assert.deepEqual(discoveredTools.map(tool => tool.name), [
    "claim_agent_profile",
    "syndicatum_list_profiles",
    "syndicatum_list_projects",
    "syndicatum_get_bootstrap",
    "syndicatum_list_participants",
    "syndicatum_list_messages",
    "syndicatum_get_message",
    "syndicatum_list_tasks",
    "syndicatum_get_task",
    "syndicatum_get_project_plan",
    "syndicatum_list_project_files",
    "syndicatum_read_project_file",
    "syndicatum_read_public_url",
    "syndicatum_create_project_folder",
    "syndicatum_upload_project_file",
    "syndicatum_rename_project_file",
    "syndicatum_move_project_file",
    "syndicatum_delete_project_file",
    "syndicatum_download_project_file",
    "syndicatum_update_task_deliverable",
    "syndicatum_update_milestone_progress",
    "syndicatum_update_deliverable_progress",
    "syndicatum_create_task",
    "syndicatum_update_task",
    "syndicatum_propose_project_details",
    "syndicatum_propose_project_plan",
    "syndicatum_propose_agent_setup",
    "syndicatum_propose_agent_profile_update",
    "syndicatum_post_message",
    "syndicatum_acknowledge_message",
    "connector_begin_login",
    "connector_complete_login",
    "connector_status",
    "connector_restart",
    "connector_migrate_server",
    "connector_background_status",
    "connector_background_install",
    "connector_configure_agent",
  ]);
  assert.deepEqual(discoveredTools.find(tool => tool.name === "syndicatum_get_message")?.annotations, {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: true,
  });
  assert.deepEqual(discoveredTools.find(tool => tool.name === "syndicatum_post_message")?.annotations, {
    readOnlyHint: false,
    destructiveHint: false,
    idempotentHint: false,
    openWorldHint: true,
  });
  assert.deepEqual(discoveredTools.find(tool => tool.name === "syndicatum_post_message")
    ?.inputSchema?.properties?.action_request_type?.enum, ["work", "approval", "review"]);
  assert.equal(discoveredTools.find(tool => tool.name === "syndicatum_post_message")
    ?.inputSchema?.properties?.attachment_file_ids?.maxItems, 20);
  assert.equal(discoveredTools.find(tool => tool.name === "syndicatum_post_message")
    ?.inputSchema?.properties?.attachment_file_ids?.uniqueItems, true);
  assert.match(discoveredTools.find(tool => tool.name === "syndicatum_propose_project_details")?.description || "", /human owner or administrator review/i);
  assert.equal(discoveredTools.find(tool => tool.name === "syndicatum_propose_project_plan")?.inputSchema?.properties?.milestones?.maxItems, 10);
  assert.equal(discoveredTools.find(tool => tool.name === "syndicatum_propose_agent_setup")?.inputSchema?.properties?.api_key, undefined);
  assert.deepEqual(discoveredTools.find(tool => tool.name === "syndicatum_update_task_deliverable")?.inputSchema?.required,
    ["profile_id", "task_id", "version", "deliverable_id", "note"]);
  assert.equal(discoveredTools.find(tool => tool.name === "syndicatum_delete_project_file")?.annotations?.destructiveHint, true);
  assert.match(discoveredTools.find(tool => tool.name === "syndicatum_upload_project_file")?.description || "", /never sent to Syndicatum/i);
  assert.equal(discoveredTools.find(tool => tool.name === "syndicatum_download_project_file")?.inputSchema?.properties?.overwrite?.default, false);
  assert.equal(discoveredTools.find(tool => tool.name === "syndicatum_read_project_file")?.inputSchema?.properties?.max_bytes?.maximum, 1048576);
  assert.equal(discoveredTools.find(tool => tool.name === "syndicatum_read_public_url")?.inputSchema?.properties?.max_bytes?.default, 65536);
  assert.match(discoveredTools.find(tool => tool.name === "syndicatum_read_public_url")?.description || "", /publicly routable HTTPS/i);
  assert.equal(discoveredTools.find(tool => tool.name === "claim_agent_profile")?.annotations?.destructiveHint, true);
  assert.deepEqual(discoveredTools.find(tool => tool.name === "claim_agent_profile")?.inputSchema?.properties?.project_id?.type, ["integer", "string"]);
  assert.deepEqual(discoveredTools.find(tool => tool.name === "claim_agent_profile")?.inputSchema?.properties?.agent_id?.type, ["integer", "string"]);
  child.kill(); await once(child, "close");
});
