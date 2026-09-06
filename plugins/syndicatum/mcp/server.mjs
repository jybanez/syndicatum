import readline from "node:readline";
import { PluginRuntime } from "./runtime.mjs";

const runtime = new PluginRuntime();
await runtime.start();

const tools = [
  {
    name: "connector_begin_login",
    description: "Begin secure browser authorization for this Codex device. No password or agent token is entered into Codex.",
    inputSchema: { type: "object", required: ["syndicatum_url", "device_name"], properties: { syndicatum_url: { type: "string" }, device_name: { type: "string" } }, additionalProperties: false },
  },
  {
    name: "connector_complete_login",
    description: "Recovery tool that completes an approved browser authorization if its Realtime signal was interrupted.",
    inputSchema: { type: "object", properties: {}, additionalProperties: false },
  },
  {
    name: "connector_status",
    description: "Return this device's Syndicatum connector state without exposing credentials.",
    inputSchema: { type: "object", properties: {}, additionalProperties: false },
  },
  {
    name: "connector_restart",
    description: "Reconnect this device's Syndicatum Realtime listener after configuration or a recoverable connection failure.",
    inputSchema: { type: "object", properties: {}, additionalProperties: false },
  },
  {
    name: "connector_background_status",
    description: "Return whether the plugin-managed background connector is installed and running on this device.",
    inputSchema: { type: "object", properties: {}, additionalProperties: false },
  },
  {
    name: "connector_background_install",
    description: "Install or update the per-user plugin-managed background connector and start it now.",
    inputSchema: { type: "object", properties: {}, additionalProperties: false },
  },
  {
    name: "connector_configure_agent",
    description: "Configure the local plugin for one existing Syndicatum agent binding. The token is protected locally and never returned.",
    inputSchema: {
      type: "object",
      required: ["syndicatum_url", "project_id", "participant_id", "agent_token"],
      properties: {
        syndicatum_url: { type: "string", description: "Base URL, normally https://chatviewer.pbb.ph" },
        project_id: { type: "string" },
        participant_id: { type: "string" },
        agent_token: { type: "string", description: "The agent's project-scoped bearer token" },
      },
      additionalProperties: false,
    },
  },
];

const lines = readline.createInterface({ input: process.stdin, crlfDelay: Infinity });
lines.on("line", async line => {
  let request;
  try { request = JSON.parse(line); } catch { return; }
  if (request.id === undefined || request.id === null) return;
  try {
    let result;
    if (request.method === "initialize") {
      result = { protocolVersion: request.params?.protocolVersion || "2024-11-05", capabilities: { tools: {} }, serverInfo: { name: "syndicatum-connector", version: "0.1.0" } };
    } else if (request.method === "ping") {
      result = {};
    } else if (request.method === "tools/list") {
      result = { tools };
    } else if (request.method === "tools/call") {
      result = await callTool(request.params?.name, request.params?.arguments || {});
    } else {
      throw Object.assign(new Error(`Method not found: ${request.method}`), { code: -32601 });
    }
    send({ jsonrpc: "2.0", id: request.id, result });
  } catch (error) {
    const data = Object.fromEntries(Object.entries({ category: error.category, hostname: error.hostname, retryable: error.retryable, status: error.status }).filter(([, value]) => value !== undefined));
    send({ jsonrpc: "2.0", id: request.id, error: { code: typeof error.code === "number" ? error.code : -32000, message: String(error?.message || error), ...(Object.keys(data).length ? { data } : {}) } });
  }
});

process.once("SIGINT", async () => { await runtime.stop(); process.exit(0); });
process.once("SIGTERM", async () => { await runtime.stop(); process.exit(0); });

async function callTool(name, args) {
  if (name === "connector_begin_login") return textResult(await runtime.beginLogin({ syndicatumUrl: args.syndicatum_url, deviceName: args.device_name }));
  if (name === "connector_complete_login") return textResult(await runtime.completeLogin());
  if (name === "connector_status") return textResult(await runtime.currentStatus());
  if (name === "connector_restart") return textResult(await runtime.start());
  if (name === "connector_background_status") return textResult(await runtime.background.status());
  if (name === "connector_background_install") return textResult(await runtime.background.ensureRunning());
  if (name === "connector_configure_agent") {
    const status = await runtime.configure({ syndicatumUrl: args.syndicatum_url, projectId: args.project_id, participantId: args.participant_id, token: args.agent_token });
    return textResult(status);
  }
  throw Object.assign(new Error(`Unknown tool: ${name}`), { code: -32602 });
}

function textResult(value) {
  return { content: [{ type: "text", text: JSON.stringify(value, null, 2) }], isError: value?.state === "error" };
}

function send(message) { process.stdout.write(`${JSON.stringify(message)}\n`); }
