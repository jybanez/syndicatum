import readline from "node:readline";
import { readFile } from "node:fs/promises";
import { PluginRuntime } from "./runtime.mjs";
import { claimAgentProfile } from "./profile-claim.mjs";
import { listAgentProfiles, publicAgentProfile } from "./agent-profile-store.mjs";
import { ProfileTimelineClient } from "./profile-timeline.mjs";
import { migrateLegacyWindowsPluginData } from "./paths.mjs";

// Codex can run as a packaged Windows app whose AppData writes are visible only
// inside the package. Move existing data once to a user-profile path that the
// external Scheduled Task and Run-key launcher can also access.
await migrateLegacyWindowsPluginData();

const pluginManifest = JSON.parse(await readFile(new URL("../.codex-plugin/plugin.json", import.meta.url), "utf8"));
const pluginVersion = String(pluginManifest.version || "").trim();
if (!pluginVersion) throw new Error("Codex plugin manifest version is missing");

const runtime = new PluginRuntime();
// MCP discovery must not wait for the background notification service. On a
// fresh install that service may need to register and start an OS launcher,
// which can take longer than Codex's MCP startup deadline. The tools remain
// usable while the listener finishes initializing in the background.
void runtime.start().catch(error => {
  runtime.status = { state: "error", error: String(error?.message || error) };
});
const timeline = new ProfileTimelineClient();

const localReadAnnotations = { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false };
const remoteReadAnnotations = { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: true };
const remoteWriteAnnotations = { readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: true };
const remoteDestructiveAnnotations = { readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: true };

const tools = [
  {
    name: "claim_agent_profile",
    description: "Claim a project-scoped Syndicatum agent identity and save it as a separate locally protected profile. The claim code and token are never returned.",
    annotations: { readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: true },
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
    annotations: localReadAnnotations,
    inputSchema: { type: "object", properties: {}, additionalProperties: false },
  },
  {
    name: "syndicatum_list_projects",
    description: "List projects visible to one locally protected Syndicatum agent profile.",
    annotations: remoteReadAnnotations,
    inputSchema: profileSchema(),
  },
  {
    name: "syndicatum_get_bootstrap",
    description: "Read the project context, this agent's project-scoped role and supervisor, permissions, work availability, and timeline attention summary.",
    annotations: remoteReadAnnotations,
    inputSchema: profileSchema(),
  },
  {
    name: "syndicatum_list_participants",
    description: "List active participants in the project bound to one Syndicatum agent profile.",
    annotations: remoteReadAnnotations,
    inputSchema: profileSchema(),
  },
  {
    name: "syndicatum_list_messages",
    description: "Read the authoritative timeline for one Syndicatum agent profile.",
    annotations: remoteReadAnnotations,
    inputSchema: { type: "object", required: ["profile_id"], properties: { profile_id: profileIdProperty(), limit: { type: "integer", minimum: 1, maximum: 200 }, before: { type: "string" }, after: { type: "string" }, addressed_to: { type: "string" }, acknowledged: { type: "string" }, q: { type: "string" }, sender: { type: "string" }, from: { type: "string" }, to: { type: "string" } }, additionalProperties: false },
  },
  {
    name: "syndicatum_get_message",
    description: "Read one authoritative Syndicatum timeline message and its reply context.",
    annotations: remoteReadAnnotations,
    inputSchema: { type: "object", required: ["profile_id", "message_id"], properties: { profile_id: profileIdProperty(), message_id: { type: ["integer", "string"] } }, additionalProperties: false },
  },
  {
    name: "syndicatum_list_tasks",
    description: "Read shared project tasks. Assignment indicates responsibility, not privacy.",
    annotations: remoteReadAnnotations,
    inputSchema: { type: "object", required: ["profile_id"], properties: { profile_id: profileIdProperty(), status: { type: "string" }, assigned_to_me: { type: "boolean" }, assignee_participant_id: { type: ["integer", "string"] }, query: { type: "string" } }, additionalProperties: false },
  },
  {
    name: "syndicatum_get_task",
    description: "Read one project task and its immutable activity history.",
    annotations: remoteReadAnnotations,
    inputSchema: { type: "object", required: ["profile_id", "task_id"], properties: { profile_id: profileIdProperty(), task_id: { type: ["integer", "string"] } }, additionalProperties: false },
  },
  {
    name: "syndicatum_get_project_plan",
    description: "Read the current milestone and deliverable hierarchy, task-backed progress, statuses, versions, and this agent's plan-stewardship permission.",
    annotations: remoteReadAnnotations,
    inputSchema: profileSchema(),
  },
  {
    name: "syndicatum_list_project_files",
    description: "List a bounded, searchable, sortable page of folders and files in the selected project folder. Returned URLs are the permanent canonical public URLs; server filesystem paths are never returned.",
    annotations: remoteReadAnnotations,
    inputSchema: { type: "object", required: ["profile_id"], properties: {
      profile_id: profileIdProperty(), folder_id: { type: "string", description: "Folder UUID, or root when omitted" },
      page: { type: ["integer", "string"], description: "One-based page, default 1" },
      per_page: { type: ["integer", "string"], description: "Files per page from 1 to 100, default 20" },
      search: { type: "string", maxLength: 100, description: "Filename, MIME type, or uploader search" },
      sort: { type: "string", enum: ["name", "type", "size", "uploader", "created", "updated", "state"] },
      direction: { type: "string", enum: ["asc", "desc"] },
    }, additionalProperties: false },
  },
  {
    name: "syndicatum_create_project_folder",
    description: "Create a project folder through the canonical audited storage service.",
    annotations: remoteWriteAnnotations,
    inputSchema: { type: "object", required: ["profile_id", "name"], properties: { profile_id: profileIdProperty(), name: { type: "string", minLength: 1, maxLength: 255 }, parent_folder_id: { type: "string" }, idempotency_key: { type: "string", minLength: 16, maxLength: 160 } }, additionalProperties: false },
  },
  {
    name: "syndicatum_upload_project_file",
    description: "Upload one local file in resumable 1 MiB chunks. The local path is used only by this device and is never sent to Syndicatum or returned. Supply replace_file_id and its latest version only after an explicit same-name replacement decision.",
    annotations: remoteWriteAnnotations,
    inputSchema: { type: "object", required: ["profile_id", "source_path"], properties: { profile_id: profileIdProperty(), source_path: { type: "string", description: "Absolute local source file path; never sent to Syndicatum" }, file_name: { type: "string", minLength: 1, maxLength: 255 }, folder_id: { type: "string" }, replace_file_id: { type: "string" }, version: { type: ["integer", "string"] }, idempotency_key: { type: "string", minLength: 16, maxLength: 160 } }, additionalProperties: false },
  },
  {
    name: "syndicatum_rename_project_file",
    description: "Rename a project file using its latest optimistic version while preserving its permanent URL.",
    annotations: remoteWriteAnnotations,
    inputSchema: fileMutationSchema({ name: { type: "string", minLength: 1, maxLength: 255 } }, ["name"]),
  },
  {
    name: "syndicatum_move_project_file",
    description: "Move a project file to another project folder using its latest optimistic version while preserving its permanent URL.",
    annotations: remoteWriteAnnotations,
    inputSchema: fileMutationSchema({ destination_folder_id: { type: "string", description: "Folder UUID, or root" } }, ["destination_folder_id"]),
  },
  {
    name: "syndicatum_delete_project_file",
    description: "Permanently make a project file unavailable and record the audited deletion. Read the latest file version and obtain explicit user authority before calling.",
    annotations: remoteDestructiveAnnotations,
    inputSchema: fileMutationSchema(),
  },
  {
    name: "syndicatum_download_project_file",
    description: "Download a project file from its permanent public URL to an explicit absolute local path. Existing files are preserved unless overwrite is true; the local destination is never sent to Syndicatum or returned.",
    annotations: { ...remoteWriteAnnotations, idempotentHint: true },
    inputSchema: { type: "object", required: ["profile_id", "file_id", "destination_path"], properties: { profile_id: profileIdProperty(), file_id: { type: "string" }, destination_path: { type: "string", description: "Absolute local destination path; never sent to Syndicatum" }, overwrite: { type: "boolean", default: false } }, additionalProperties: false },
  },
  {
    name: "syndicatum_update_task_deliverable",
    description: "Link or unlink an existing task to an approved-plan deliverable using the task's latest version. Requires the agent's explicit plan-progress permission and an evidence note; cannot change task ownership or lifecycle.",
    annotations: remoteWriteAnnotations,
    inputSchema: { type: "object", required: ["profile_id", "task_id", "version", "deliverable_id", "note"], properties: { profile_id: profileIdProperty(), task_id: { type: ["integer", "string"] }, version: { type: ["integer", "string"] }, deliverable_id: { type: ["integer", "string", "null"], description: "Target deliverable, or null to unlink the task" }, note: { type: "string", minLength: 1, maxLength: 4000 } }, additionalProperties: false },
  },
  {
    name: "syndicatum_update_milestone_progress",
    description: "Update only a milestone status using its latest version. Requires the agent's explicit plan-progress permission and an auditable note; cannot change plan structure.",
    annotations: remoteWriteAnnotations,
    inputSchema: { type: "object", required: ["profile_id", "milestone_id", "version", "status", "note"], properties: { profile_id: profileIdProperty(), milestone_id: { type: ["integer", "string"] }, version: { type: ["integer", "string"] }, status: { type: "string", enum: ["planned", "in_progress", "completed", "at_risk", "cancelled"] }, note: { type: "string", minLength: 1, maxLength: 4000 } }, additionalProperties: false },
  },
  {
    name: "syndicatum_update_deliverable_progress",
    description: "Update only a deliverable status using its latest version. Requires the agent's explicit plan-progress permission and an auditable note; cannot change plan structure.",
    annotations: remoteWriteAnnotations,
    inputSchema: { type: "object", required: ["profile_id", "deliverable_id", "version", "status", "note"], properties: { profile_id: profileIdProperty(), deliverable_id: { type: ["integer", "string"] }, version: { type: ["integer", "string"] }, status: { type: "string", enum: ["planned", "in_progress", "in_review", "approved", "completed", "blocked", "cancelled"] }, note: { type: "string", minLength: 1, maxLength: 4000 } }, additionalProperties: false },
  },
  {
    name: "syndicatum_create_task",
    description: "Create a shared project task as the selected agent. The agent is recorded automatically as the immutable task giver.",
    annotations: remoteWriteAnnotations,
    inputSchema: { type: "object", required: ["profile_id", "title"], properties: { profile_id: profileIdProperty(), title: { type: "string", minLength: 1, maxLength: 180 }, description: { type: "string" }, acceptance_criteria: { type: "string" }, priority: { type: "string", enum: ["low", "normal", "high", "urgent"] }, assignee_participant_id: { type: ["integer", "string"] }, due_at: { type: "string", description: "Optional date and time" }, source_message_id: { type: ["integer", "string"] } }, additionalProperties: false },
  },
  {
    name: "syndicatum_update_task",
    description: "Move an assigned task through an authorized lifecycle using its latest version.",
    annotations: remoteWriteAnnotations,
    inputSchema: { type: "object", required: ["profile_id", "task_id", "version"], properties: { profile_id: profileIdProperty(), task_id: { type: ["integer", "string"] }, version: { type: ["integer", "string"] }, status: { type: "string", enum: ["open", "in_progress", "in_review", "blocked", "completed", "cancelled"] }, blocked_reason: { type: "string" }, completion_summary: { type: "string" }, note: { type: "string" } }, additionalProperties: false },
  },
  {
    name: "syndicatum_propose_project_details",
    description: "Submit suggested project name, description, or operating-instruction improvements for human owner or administrator review. This never changes the project automatically.",
    annotations: remoteWriteAnnotations,
    inputSchema: { type: "object", required: ["profile_id"], properties: { profile_id: profileIdProperty(), name: { type: "string", minLength: 1, maxLength: 160 }, description: { type: "string", maxLength: 10000 }, instructions: { type: "string", maxLength: 50000 }, rationale: { type: "string", maxLength: 4000 } }, additionalProperties: false },
  },
  {
    name: "syndicatum_propose_project_plan",
    description: "Submit a bounded, create-only hierarchy of milestones and deliverables for human owner or administrator review. Approval applies it atomically; this never changes the project automatically.",
    annotations: remoteWriteAnnotations,
    inputSchema: {
      type: "object",
      required: ["profile_id"],
      additionalProperties: false,
      properties: {
        profile_id: profileIdProperty(),
        milestones: {
          type: "array", maxItems: 10,
          items: {
            type: "object", required: ["title"], additionalProperties: false,
            properties: {
              title: { type: "string", minLength: 1, maxLength: 180 },
              description: { type: "string", maxLength: 10000 },
              target_date: { type: "string", format: "date" },
              deliverables: {
                type: "array", maxItems: 20,
                items: {
                  type: "object", required: ["title"], additionalProperties: false,
                  properties: {
                    title: { type: "string", minLength: 1, maxLength: 180 },
                    description: { type: "string", maxLength: 10000 },
                    due_date: { type: "string", format: "date" },
                    owner_participant_id: { type: ["integer", "string", "null"] },
                  },
                },
              },
            },
          },
        },
        standalone_deliverables: {
          type: "array", maxItems: 20,
          items: {
            type: "object", required: ["title"], additionalProperties: false,
            properties: {
              title: { type: "string", minLength: 1, maxLength: 180 },
              description: { type: "string", maxLength: 10000 },
              due_date: { type: "string", format: "date" },
              owner_participant_id: { type: ["integer", "string", "null"] },
            },
          },
        },
        rationale: { type: "string", maxLength: 4000 },
      },
    },
  },
  {
    name: "syndicatum_propose_agent_setup",
    description: "Suggest a new project agent profile for human owner or administrator review. Credentials, activation, scopes, webhooks, and runtime paths are not accepted.",
    annotations: remoteWriteAnnotations,
    inputSchema: { type: "object", required: ["profile_id", "display_name"], properties: { profile_id: profileIdProperty(), display_name: { type: "string", minLength: 1, maxLength: 120 }, provider: { type: "string", maxLength: 120 }, runtime_name: { type: "string", maxLength: 160 }, role_title: { type: "string", maxLength: 120 }, role_summary: { type: "string", maxLength: 4000 }, role_instructions: { type: "string", maxLength: 20000 }, supervising_participant_id: { type: ["integer", "string", "null"] }, rationale: { type: "string", maxLength: 4000 } }, additionalProperties: false },
  },
  {
    name: "syndicatum_propose_agent_profile_update",
    description: "Suggest changes to an existing project agent's non-secret role or profile fields for human owner or administrator review. This never changes the agent automatically.",
    annotations: remoteWriteAnnotations,
    inputSchema: { type: "object", required: ["profile_id", "target_agent_id"], properties: { profile_id: profileIdProperty(), target_agent_id: { type: ["integer", "string"] }, display_name: { type: "string", minLength: 1, maxLength: 120 }, provider: { type: "string", maxLength: 120 }, runtime_name: { type: "string", maxLength: 160 }, role_title: { type: "string", maxLength: 120 }, role_summary: { type: "string", maxLength: 4000 }, role_instructions: { type: "string", maxLength: 20000 }, supervising_participant_id: { type: ["integer", "string", "null"] }, rationale: { type: "string", maxLength: 4000 } }, additionalProperties: false },
  },
  {
    name: "syndicatum_post_message",
    description: "Post, reply, mention, directly address, or broadcast as one explicitly selected Syndicatum agent profile, optionally attaching up to 20 existing files from that project.",
    annotations: remoteWriteAnnotations,
    inputSchema: { type: "object", required: ["profile_id", "body"], properties: { profile_id: profileIdProperty(), body: { type: "string" }, direct_participant_ids: { type: "array", items: { type: ["integer", "string"] } }, mention_participant_ids: { type: "array", items: { type: ["integer", "string"] } }, attachment_file_ids: { type: "array", maxItems: 20, uniqueItems: true, description: "Canonical project-file UUIDs to attach in display order. Each file must be available in this profile's project.", items: { type: "string" } }, broadcast: { type: "boolean" }, action_requested: { type: "boolean", description: "Create a Responsibility Inbox item for each direct recipient. Omit or false for updates and FYI messages." }, action_request_type: { type: "string", enum: ["work", "approval", "review"], description: "Response workflow for an action request. Omission defaults to work." }, reply_to_message_id: { type: ["integer", "string"] }, idempotency_key: { type: "string" }, correlation_id: { type: "string" } }, additionalProperties: false },
  },
  {
    name: "syndicatum_acknowledge_message",
    description: "Acknowledge one message as the explicitly selected Syndicatum agent profile.",
    annotations: { ...remoteWriteAnnotations, idempotentHint: true },
    inputSchema: { type: "object", required: ["profile_id", "message_id"], properties: { profile_id: profileIdProperty(), message_id: { type: ["integer", "string"] } }, additionalProperties: false },
  },
  {
    name: "connector_begin_login",
    description: "Begin secure browser authorization for this Codex device. No password or agent token is entered into Codex.",
    annotations: remoteWriteAnnotations,
    inputSchema: { type: "object", required: ["syndicatum_url", "device_name"], properties: { syndicatum_url: { type: "string" }, device_name: { type: "string" } }, additionalProperties: false },
  },
  {
    name: "connector_complete_login",
    description: "Recovery tool that completes an approved browser authorization if its Realtime signal was interrupted.",
    annotations: remoteWriteAnnotations,
    inputSchema: { type: "object", properties: {}, additionalProperties: false },
  },
  {
    name: "connector_status",
    description: "Return this device's Syndicatum connector state without exposing credentials.",
    annotations: localReadAnnotations,
    inputSchema: { type: "object", properties: {}, additionalProperties: false },
  },
  {
    name: "connector_restart",
    description: "Reconnect this device's Syndicatum Realtime listener after configuration or a recoverable connection failure.",
    annotations: { ...remoteWriteAnnotations, idempotentHint: true },
    inputSchema: { type: "object", properties: {}, additionalProperties: false },
  },
  {
    name: "connector_migrate_server",
    description: "Validate a replacement Syndicatum server, verify existing protected device and agent credentials there, migrate origin-scoped local profiles, and restart the background listener.",
    annotations: { readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: true },
    inputSchema: { type: "object", required: ["syndicatum_url"], properties: { syndicatum_url: { type: "string", description: "New Syndicatum server base URL, for example https://syndicatumserver.com" } }, additionalProperties: false },
  },
  {
    name: "connector_background_status",
    description: "Return whether the plugin-managed background connector is installed and running on this device.",
    annotations: localReadAnnotations,
    inputSchema: { type: "object", properties: {}, additionalProperties: false },
  },
  {
    name: "connector_background_install",
    description: "Install or update the per-user plugin-managed background connector and start it now.",
    annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    inputSchema: { type: "object", properties: {}, additionalProperties: false },
  },
  {
    name: "connector_configure_agent",
    description: "Configure the local plugin for one existing Syndicatum agent binding. The token is protected locally and never returned.",
    annotations: { readOnlyHint: false, destructiveHint: true, idempotentHint: true, openWorldHint: true },
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
      result = { protocolVersion: request.params?.protocolVersion || "2024-11-05", capabilities: { tools: {} }, serverInfo: { name: "syndicatum-connector", version: pluginVersion } };
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
  if (name === "syndicatum_get_bootstrap") return textResult(await timeline.bootstrap(args.profile_id));
  if (name === "syndicatum_list_participants") return textResult(await timeline.participants(args.profile_id));
  if (name === "syndicatum_list_messages") return textResult(await timeline.messages(args.profile_id, args));
  if (name === "syndicatum_get_message") return textResult(await timeline.message(args.profile_id, args.message_id));
  if (name === "syndicatum_list_tasks") return textResult(await timeline.tasks(args.profile_id, args));
  if (name === "syndicatum_get_task") return textResult(await timeline.task(args.profile_id, args.task_id));
  if (name === "syndicatum_get_project_plan") return textResult(await timeline.projectPlan(args.profile_id));
  if (name === "syndicatum_list_project_files") return textResult(await timeline.projectFiles(args.profile_id, args));
  if (name === "syndicatum_create_project_folder") return textResult(await timeline.createProjectFolder(args.profile_id, args));
  if (name === "syndicatum_upload_project_file") return textResult(await timeline.uploadProjectFile(args.profile_id, args));
  if (name === "syndicatum_rename_project_file") return textResult(await timeline.renameProjectFile(args.profile_id, args));
  if (name === "syndicatum_move_project_file") return textResult(await timeline.moveProjectFile(args.profile_id, args));
  if (name === "syndicatum_delete_project_file") return textResult(await timeline.deleteProjectFile(args.profile_id, args));
  if (name === "syndicatum_download_project_file") return textResult(await timeline.downloadProjectFile(args.profile_id, args));
  if (name === "syndicatum_update_task_deliverable") return textResult(await timeline.updateTaskDeliverable(args.profile_id, args.task_id, args));
  if (name === "syndicatum_update_milestone_progress") return textResult(await timeline.updateMilestoneProgress(args.profile_id, args.milestone_id, args));
  if (name === "syndicatum_update_deliverable_progress") return textResult(await timeline.updateDeliverableProgress(args.profile_id, args.deliverable_id, args));
  if (name === "syndicatum_create_task") return textResult(await timeline.createTask(args.profile_id, args));
  if (name === "syndicatum_update_task") return textResult(await timeline.updateTask(args.profile_id, args.task_id, args));
  if (name === "syndicatum_propose_project_details") return textResult(await timeline.proposeProjectDetails(args.profile_id, args));
  if (name === "syndicatum_propose_project_plan") return textResult(await timeline.proposeProjectPlan(args.profile_id, args));
  if (name === "syndicatum_propose_agent_setup") return textResult(await timeline.proposeAgentSetup(args.profile_id, args));
  if (name === "syndicatum_propose_agent_profile_update") return textResult(await timeline.proposeAgentProfileUpdate(args.profile_id, args));
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
function fileMutationSchema(extra = {}, extraRequired = []) {
  return { type: "object", required: ["profile_id", "file_id", "version", ...extraRequired], additionalProperties: false,
    properties: { profile_id: profileIdProperty(), file_id: { type: "string" }, version: { type: ["integer", "string"] },
      idempotency_key: { type: "string", minLength: 16, maxLength: 160 }, ...extra } };
}
