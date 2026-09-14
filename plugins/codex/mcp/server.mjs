import readline from "node:readline";
import { PluginRuntime } from "./runtime.mjs";
import { claimAgentProfile } from "./profile-claim.mjs";
import { listAgentProfiles, publicAgentProfile } from "./agent-profile-store.mjs";
import { ProfileTimelineClient } from "./profile-timeline.mjs";

const runtime = new PluginRuntime();
await runtime.start();
const timeline = new ProfileTimelineClient();

const tools = [
  {
    name: "claim_agent_profile",
    description: "Claim a project-scoped Syndicatum agent identity and save it as a separate locally protected profile. The claim code and token are never returned.",
    inputSchema: {
      type: "object",
      required: ["syndicatum_url", "project", "identity", "claim_code"],
      properties: {
        syndicatum_url: { type: "string", description: "Syndicatum server base URL, for example https://syndicatumserver.com" },
        project: { type: "string", description: "Visible Syndicatum project name or slug" },
        identity: { type: "string", description: "Visible agent identity name" },
        claim_code: { type: "string", description: "One-time claim code issued by a Syndicatum project administrator" },
        project_root: { type: "string", description: "Optional project directory; defaults to the current task directory" },
        replace_existing: { type: "boolean", description: "Replace an existing project credential only when explicitly intended" },
      },
      additionalProperties: false,
    },
  },
  {
    name: "syndicatum_list_profiles",
    description: "List locally protected Syndicatum agent profiles without returning credentials.",
    inputSchema: { type: "object", properties: {}, additionalProperties: false },
  },
  {
    name: "syndicatum_list_projects",
    description: "List projects visible to one locally protected Syndicatum agent profile.",
    inputSchema: profileSchema(),
  },
  {
    name: "syndicatum_list_participants",
    description: "List active participants in the project bound to one Syndicatum agent profile.",
    inputSchema: profileSchema(),
  },
  {
    name: "syndicatum_list_messages",
    description: "Read the authoritative timeline for one Syndicatum agent profile.",
    inputSchema: { type: "object", required: ["profile_id"], properties: { profile_id: profileIdProperty(), limit: { type: "integer", minimum: 1, maximum: 200 }, before: { type: "string" }, after: { type: "string" }, addressed_to: { type: "string" }, acknowledged: { type: "string" }, q: { type: "string" }, sender: { type: "string" }, from: { type: "string" }, to: { type: "string" } }, additionalProperties: false },
  },
  {
    name: "syndicatum_get_message",
    description: "Read one authoritative Syndicatum timeline message and its reply context.",
    inputSchema: { type: "object", required: ["profile_id", "message_id"], properties: { profile_id: profileIdProperty(), message_id: { type: ["integer", "string"] } }, additionalProperties: false },
  },
  {
    name: "syndicatum_post_message",
    description: "Post, reply, mention, directly address, or broadcast as one explicitly selected Syndicatum agent profile.",
    inputSchema: { type: "object", required: ["profile_id", "body"], properties: { profile_id: profileIdProperty(), body: { type: "string" }, direct_participant_ids: { type: "array", items: { type: ["integer", "string"] } }, mention_participant_ids: { type: "array", items: { type: ["integer", "string"] } }, broadcast: { type: "boolean" }, reply_to_message_id: { type: ["integer", "string"] }, idempotency_key: { type: "string" }, correlation_id: { type: "string" } }, additionalProperties: false },
  },
  {
    name: "syndicatum_acknowledge_message",
    description: "Acknowledge one message as the explicitly selected Syndicatum agent profile.",
    inputSchema: { type: "object", required: ["profile_id", "message_id"], properties: { profile_id: profileIdProperty(), message_id: { type: ["integer", "string"] } }, additionalProperties: false },
  },
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
    name: "connector_migrate_server",
    description: "Validate a replacement Syndicatum server, verify existing protected device and agent credentials there, migrate origin-scoped local profiles, and restart the background listener.",
    inputSchema: { type: "object", required: ["syndicatum_url"], properties: { syndicatum_url: { type: "string", description: "New Syndicatum server base URL, for example https://syndicatumserver.com" } }, additionalProperties: false },
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
        syndicatum_url: { type: "string", description: "Syndicatum server base URL, for example https://syndicatumserver.com" },
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
  if (name === "claim_agent_profile") return textResult(await claimAgentProfile({ syndicatumUrl: args.syndicatum_url, project: args.project, identity: args.identity, claimCode: args.claim_code, projectRoot: args.project_root, replaceExisting: args.replace_existing === true }));
  if (name === "syndicatum_list_profiles") return textResult((await listAgentProfiles()).map(publicAgentProfile));
  if (name === "syndicatum_list_projects") return textResult(await timeline.projects(args.profile_id));
  if (name === "syndicatum_list_participants") return textResult(await timeline.participants(args.profile_id));
  if (name === "syndicatum_list_messages") return textResult(await timeline.messages(args.profile_id, args));
  if (name === "syndicatum_get_message") return textResult(await timeline.message(args.profile_id, args.message_id));
  if (name === "syndicatum_post_message") return textResult(await timeline.post(args.profile_id, args));
  if (name === "syndicatum_acknowledge_message") return textResult(await timeline.acknowledge(args.profile_id, args.message_id));
  if (name === "connector_begin_login") return textResult(await runtime.beginLogin({ syndicatumUrl: args.syndicatum_url, deviceName: args.device_name }));
  if (name === "connector_complete_login") return textResult(await runtime.completeLogin());
  if (name === "connector_status") return textResult(await runtime.currentStatus());
  if (name === "connector_restart") return textResult(await runtime.start());
  if (name === "connector_migrate_server") return textResult(await runtime.migrateServer(args.syndicatum_url));
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

function profileIdProperty() { return { type: "string", description: "Exact non-secret profile ID supplied by the connector notification or claim result" }; }
function profileSchema() { return { type: "object", required: ["profile_id"], properties: { profile_id: profileIdProperty() }, additionalProperties: false }; }
