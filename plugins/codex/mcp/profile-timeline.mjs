import { createHash, randomUUID } from "node:crypto";
import { open, stat } from "node:fs/promises";
import path from "node:path";
import { loadAgentProfile, publicAgentProfile } from "./agent-profile-store.mjs";
import { SyndicatumClient } from "./syndicatum-client.mjs";

export class ProfileTimelineClient {
  constructor(env = process.env, fetchImpl = fetch, loadProfile = loadAgentProfile) {
    Object.assign(this, { env, fetchImpl, loadProfile });
  }

  async context(profileId) {
    const profile = await this.loadProfile(String(profileId || "").trim(), this.env);
    const client = new SyndicatumClient({
      syndicatumUrl: profile.syndicatum_url,
      projectId: String(profile.project_id),
      participantId: String(profile.participant_id),
      token: profile.token,
    }, this.fetchImpl);
    await client.validateBinding();
    return { profile, client };
  }

  async projects(profileId) {
    const { profile, client } = await this.context(profileId);
    const result = await client.request("/api/v1/projects.php");
    return { profile: publicAgentProfile(profile), projects: result.data ?? [] };
  }

  async participants(profileId) {
    const { profile, client } = await this.context(profileId);
    const result = await client.request(`/api/v1/project-participants.php?project_id=${encodeURIComponent(profile.project_id)}&status=active`);
    return { profile: publicAgentProfile(profile), participants: result.data ?? [] };
  }

  async bootstrap(profileId) {
    const { profile, client } = await this.context(profileId);
    const result = await client.request(`/api/v1/project-bootstrap.php?project_id=${encodeURIComponent(profile.project_id)}`);
    return { profile: publicAgentProfile(profile), bootstrap: result.data ?? result };
  }

  async messages(profileId, input = {}) {
    const { profile, client } = await this.context(profileId);
    const query = new URLSearchParams({ project_id: String(profile.project_id), limit: String(clamp(input.limit, 1, 200, 50)) });
    for (const key of ["before", "after", "addressed_to", "acknowledged", "q", "sender", "from", "to"]) {
      if (String(input[key] ?? "").trim()) query.set(key, String(input[key]).trim());
    }
    const result = await client.request(`/api/v1/project-messages.php?${query}`);
    return { profile: publicAgentProfile(profile), messages: result.data ?? [], ...(result.page ? { page: result.page } : {}) };
  }

  async message(profileId, messageId) {
    const { profile, client } = await this.context(profileId);
    const id = positiveId(messageId, "message");
    const result = await client.request(`/api/v1/project-message.php?project_id=${encodeURIComponent(profile.project_id)}&id=${encodeURIComponent(id)}`);
    return { profile: publicAgentProfile(profile), message: result.data ?? result.message ?? result };
  }

  async tasks(profileId, input = {}) {
    const { profile, client } = await this.context(profileId);
    const query = new URLSearchParams({ project_id: String(profile.project_id) });
    if (String(input.status || "").trim()) query.set("status", String(input.status).trim());
    if (input.assigned_to_me === true) query.set("assignee", "me");
    else if (input.assignee_participant_id) query.set("assignee", positiveId(input.assignee_participant_id, "participant"));
    if (String(input.query || "").trim()) query.set("q", String(input.query).trim());
    const result = await client.request(`/api/v1/project-tasks.php?${query}`);
    return { profile: publicAgentProfile(profile), tasks: result.data ?? [] };
  }

  async task(profileId, taskId) {
    const { profile, client } = await this.context(profileId);
    const id = positiveId(taskId, "task");
    const result = await client.request(`/api/v1/project-task.php?project_id=${encodeURIComponent(profile.project_id)}&id=${encodeURIComponent(id)}&include=events`);
    return { profile: publicAgentProfile(profile), task: result.data ?? result };
  }

  async projectPlan(profileId) {
    const { profile, client } = await this.context(profileId);
    const result = await client.request(`/api/v1/project-plan.php?project_id=${encodeURIComponent(profile.project_id)}`);
    return { profile: publicAgentProfile(profile), plan: result.data ?? result };
  }

  async projectFiles(profileId, input = {}) {
    const { profile, client } = await this.context(profileId);
    const query = new URLSearchParams({ project_id: String(profile.project_id) });
    const folderId = String(input.folder_id || "root").trim();
    if (folderId !== "root") query.set("folder_id", projectFileId(folderId, "folder"));
    const result = await client.request(`/api/v1/project-files.php?${query}`);
    const data = result.data ?? result;
    return { profile: publicAgentProfile(profile), files: publicFilePayload(client, data) };
  }

  async createProjectFolder(profileId, input = {}) {
    return this.projectFileMutation(profileId, {
      operation: "create_folder", name: requiredText(input.name, "Folder name"),
      parent_folder_id: optionalFolderId(input.parent_folder_id),
    }, input.idempotency_key);
  }

  async renameProjectFile(profileId, input = {}) {
    return this.projectFileMutation(profileId, {
      operation: "rename_file", file_id: projectFileId(input.file_id, "file"),
      version: positiveId(input.version, "file version"), name: requiredText(input.name, "File name"),
    }, input.idempotency_key);
  }

  async moveProjectFile(profileId, input = {}) {
    return this.projectFileMutation(profileId, {
      operation: "move_file", file_id: projectFileId(input.file_id, "file"),
      version: positiveId(input.version, "file version"),
      destination_folder_id: optionalFolderId(input.destination_folder_id),
    }, input.idempotency_key);
  }

  async deleteProjectFile(profileId, input = {}) {
    return this.projectFileMutation(profileId, {
      operation: "delete_file", file_id: projectFileId(input.file_id, "file"),
      version: positiveId(input.version, "file version"),
    }, input.idempotency_key);
  }

  async projectFileMutation(profileId, payload, idempotencyKey) {
    const { profile, client } = await this.context(profileId);
    const key = normalizedIdempotencyKey(idempotencyKey);
    const result = await client.request(`/api/v1/project-files.php?project_id=${encodeURIComponent(profile.project_id)}`, {
      method: "POST", headers: { "Idempotency-Key": key }, body: JSON.stringify(payload),
    });
    return { profile: publicAgentProfile(profile), result: publicFilePayload(client, result.data ?? result) };
  }

  async uploadProjectFile(profileId, input = {}) {
    const { profile, client } = await this.context(profileId);
    const sourcePath = String(input.source_path || "").trim();
    if (!path.isAbsolute(sourcePath)) throw new Error("Source path must be an absolute local file path.");
    const source = await stat(sourcePath);
    if (!source.isFile()) throw new Error("Source path must identify a regular file.");
    if (source.size < 1) throw new Error("The source file is empty.");
    const fileName = requiredText(input.file_name || path.basename(sourcePath), "File name");
    const key = normalizedIdempotencyKey(input.idempotency_key);
    const uploadId = stableUploadId(profile.profile_id, key);
    const chunkBytes = 1024 * 1024;
    const chunkCount = Math.ceil(source.size / chunkBytes);
    const folderId = optionalFolderId(input.folder_id);
    const replaceId = input.replace_file_id === undefined || input.replace_file_id === null || input.replace_file_id === ""
      ? null : projectFileId(input.replace_file_id, "replacement file");
    const replacementVersion = replaceId ? positiveId(input.version, "file version") : null;
    const folderQuery = new URLSearchParams({ project_id: String(profile.project_id) });
    if (folderId !== "root") folderQuery.set("folder_id", folderId);
    const folderResult = await client.request(`/api/v1/project-files.php?${folderQuery}`);
    const existingFiles = Array.isArray((folderResult.data ?? folderResult)?.files) ? (folderResult.data ?? folderResult).files : [];
    const duplicate = existingFiles.find(file => String(file?.name || "").localeCompare(fileName, undefined, { sensitivity: "accent" }) === 0);
    if (duplicate && !replaceId) {
      throw new Error("A file with that name already exists in the selected folder. Obtain an explicit replacement decision, then retry with replace_file_id and its latest version.");
    }
    if (replaceId && (!duplicate || String(duplicate.id) !== replaceId || String(duplicate.version) !== String(replacementVersion))) {
      throw new Error("Replacement target does not match the same-name file and latest version in the selected folder. Reload the folder and reassess.");
    }
    const handle = await open(sourcePath, "r");
    let finalResult = null;
    try {
      for (let index = 0; index < chunkCount; index += 1) {
        const length = Math.min(chunkBytes, source.size - (index * chunkBytes));
        const bytes = Buffer.allocUnsafe(length);
        let filled = 0;
        while (filled < length) {
          const read = await handle.read(bytes, filled, length - filled, (index * chunkBytes) + filled);
          if (read.bytesRead < 1) throw new Error("The local source file changed or could not be read completely.");
          filled += read.bytesRead;
        }
        const form = new FormData();
        form.set("operation", "upload_chunk");
        form.set("upload_id", uploadId);
        form.set("chunk_index", String(index));
        form.set("chunk_count", String(chunkCount));
        form.set("total_size", String(source.size));
        form.set("target_operation", replaceId ? "replace_file" : "upload");
        form.set("original_name", fileName);
        form.set("folder_id", folderId);
        if (replaceId) { form.set("file_id", replaceId); form.set("version", String(replacementVersion)); }
        form.set("file", new Blob([bytes]), fileName);
        finalResult = await client.request(`/api/v1/project-files.php?project_id=${encodeURIComponent(profile.project_id)}`, {
          method: "POST", headers: { "Idempotency-Key": key }, body: form,
        });
      }
    } finally {
      await handle.close();
    }
    return { profile: publicAgentProfile(profile), upload: publicFilePayload(client, finalResult?.data ?? finalResult ?? {}) };
  }

  async downloadProjectFile(profileId, input = {}) {
    const { profile, client } = await this.context(profileId);
    const fileId = projectFileId(input.file_id, "file");
    const destination = String(input.destination_path || "").trim();
    if (!path.isAbsolute(destination)) throw new Error("Destination path must be an absolute local file path.");
    const parent = await stat(path.dirname(destination));
    if (!parent.isDirectory()) throw new Error("Destination directory does not exist.");
    const url = client.publicUrl(`/files/${encodeURIComponent(fileId)}`);
    const response = await this.fetchImpl(url, { headers: { Accept: "*/*" } });
    if (!response.ok) throw Object.assign(new Error(`Project file download failed with HTTP ${response.status}.`), { status: response.status });
    const bytes = Buffer.from(await response.arrayBuffer());
    const destinationHandle = await open(destination, input.overwrite === true ? "w" : "wx");
    try { await destinationHandle.writeFile(bytes); } finally { await destinationHandle.close(); }
    return { profile: publicAgentProfile(profile), download: {
      saved: true, file_id: fileId, url, size_bytes: bytes.length,
      content_type: response.headers.get("content-type") || "application/octet-stream",
    } };
  }

  async updateTaskDeliverable(profileId, taskId, input = {}) {
    const { profile, client } = await this.context(profileId);
    const id = positiveId(taskId, "task");
    const payload = {
      version: Number(positiveId(input.version, "task version")),
      deliverable_id: input.deliverable_id === null || input.deliverable_id === ""
        ? null
        : Number(positiveId(input.deliverable_id, "deliverable")),
      note: String(input.note || "").trim(),
    };
    if (!payload.note) throw new Error("An evidence note is required for task-to-deliverable updates.");
    const result = await client.request(`/api/v1/project-task-deliverable.php?project_id=${encodeURIComponent(profile.project_id)}&id=${encodeURIComponent(id)}`, { method: "PATCH", body: JSON.stringify(payload) });
    return { profile: publicAgentProfile(profile), task: result.data ?? result };
  }

  async updateMilestoneProgress(profileId, milestoneId, input = {}) {
    return this.updatePlanProgress(profileId, "milestone", milestoneId, input);
  }

  async updateDeliverableProgress(profileId, deliverableId, input = {}) {
    return this.updatePlanProgress(profileId, "deliverable", deliverableId, input);
  }

  async updatePlanProgress(profileId, kind, subjectId, input = {}) {
    const { profile, client } = await this.context(profileId);
    const id = positiveId(subjectId, kind);
    const payload = { version: Number(positiveId(input.version, `${kind} version`)), status: String(input.status || "").trim(), note: String(input.note || "").trim() };
    if (!payload.status) throw new Error(`A ${kind} status is required.`);
    if (!payload.note) throw new Error("A progress note is required for agent updates.");
    const result = await client.request(`/api/v1/project-${kind}-progress.php?project_id=${encodeURIComponent(profile.project_id)}&id=${encodeURIComponent(id)}`, { method: "PATCH", body: JSON.stringify(payload) });
    return { profile: publicAgentProfile(profile), [kind]: result.data ?? result };
  }

  async createTask(profileId, input = {}) {
    const { profile, client } = await this.context(profileId);
    const title = String(input.title || "").trim();
    if (!title) throw new Error("A task title is required.");
    const payload = { title };
    for (const key of ["description", "acceptance_criteria", "priority", "due_at"]) {
      if (input[key] !== undefined) payload[key] = input[key];
    }
    if (input.assignee_participant_id !== undefined && input.assignee_participant_id !== null && input.assignee_participant_id !== "") {
      payload.assignee_participant_id = Number(positiveId(input.assignee_participant_id, "participant"));
    }
    if (input.source_message_id !== undefined && input.source_message_id !== null && input.source_message_id !== "") {
      payload.source_message_id = Number(positiveId(input.source_message_id, "source message"));
    }
    const result = await client.request(`/api/v1/project-tasks.php?project_id=${encodeURIComponent(profile.project_id)}`, { method: "POST", body: JSON.stringify(payload) });
    return { profile: publicAgentProfile(profile), task: result.data ?? result };
  }

  async updateTask(profileId, taskId, input = {}) {
    const { profile, client } = await this.context(profileId);
    const id = positiveId(taskId, "task");
    const version = Number(positiveId(input.version, "task version"));
    const payload = { version };
    for (const key of ["status", "blocked_reason", "completion_summary", "note"]) {
      if (input[key] !== undefined) payload[key] = input[key];
    }
    const result = await client.request(`/api/v1/project-task.php?project_id=${encodeURIComponent(profile.project_id)}&id=${encodeURIComponent(id)}`, { method: "PATCH", body: JSON.stringify(payload) });
    return { profile: publicAgentProfile(profile), task: result.data ?? result };
  }

  async proposeProjectDetails(profileId, input = {}) {
    return this.propose(profileId, "project_details", copyDefined(input, ["name", "description", "instructions", "rationale"]));
  }

  async proposeProjectPlan(profileId, input = {}) {
    return this.propose(profileId, "project_plan", copyDefined(input, ["milestones", "standalone_deliverables", "rationale"]));
  }

  async proposeAgentSetup(profileId, input = {}) {
    const displayName = String(input.display_name || "").trim();
    if (!displayName) throw new Error("An agent display name is required.");
    const payload = copyDefined(input, ["provider", "runtime_name", "role_title", "role_summary", "role_instructions", "rationale"]);
    payload.display_name = displayName;
    if (input.supervising_participant_id !== undefined) payload.supervising_participant_id = nullablePositiveId(input.supervising_participant_id, "supervising participant");
    return this.propose(profileId, "agent_setup", payload);
  }

  async proposeAgentProfileUpdate(profileId, input = {}) {
    const payload = copyDefined(input, ["display_name", "provider", "runtime_name", "role_title", "role_summary", "role_instructions", "rationale"]);
    payload.target_agent_id = Number(positiveId(input.target_agent_id, "target agent"));
    if (input.supervising_participant_id !== undefined) payload.supervising_participant_id = nullablePositiveId(input.supervising_participant_id, "supervising participant");
    return this.propose(profileId, "agent_profile_update", payload);
  }

  async propose(profileId, proposalType, input = {}) {
    const { profile, client } = await this.context(profileId);
    const payload = { ...input, proposal_type: proposalType };
    const result = await client.request(`/api/v1/project-change-proposals.php?project_id=${encodeURIComponent(profile.project_id)}`, {
      method: "POST",
      body: JSON.stringify(payload),
    });
    return { profile: publicAgentProfile(profile), proposal: result.data ?? result };
  }

  async post(profileId, input = {}) {
    const { profile, client } = await this.context(profileId);
    const body = String(input.body || "").trim();
    if (!body) throw new Error("A timeline message body is required.");
    const idempotencyKey = String(input.idempotency_key || `profile:${profile.profile_id}:${randomUUID()}`).trim();
    const payload = {
      body,
      direct_participant_ids: numericIds(input.direct_participant_ids),
      mention_participant_ids: numericIds(input.mention_participant_ids),
      attachment_file_ids: projectFileIds(input.attachment_file_ids),
      broadcast: input.broadcast === true,
      action_requested: input.action_requested === true,
      idempotency_key: idempotencyKey,
    };
    if (payload.action_requested) {
      const requestType = String(input.action_request_type || "work").trim().toLowerCase();
      if (!["work", "approval", "review"].includes(requestType)) {
        throw new Error("Action request type must be work, approval, or review.");
      }
      payload.action_request_type = requestType;
    } else if (input.action_request_type !== undefined
        && input.action_request_type !== null
        && String(input.action_request_type).trim() !== "") {
      throw new Error("Action request type requires action_requested to be true.");
    }
    if (input.reply_to_message_id !== undefined && input.reply_to_message_id !== null) payload.reply_to_message_id = Number(positiveId(input.reply_to_message_id, "reply message"));
    if (String(input.correlation_id || "").trim()) payload.correlation_id = String(input.correlation_id).trim();
    const result = await client.request(`/api/v1/project-messages.php?project_id=${encodeURIComponent(profile.project_id)}`, {
      method: "POST", headers: { "Idempotency-Key": idempotencyKey }, body: JSON.stringify(payload),
    });
    return { profile: publicAgentProfile(profile), message: result.data ?? result.message ?? result };
  }

  async acknowledge(profileId, messageId) {
    const { profile, client } = await this.context(profileId);
    const id = positiveId(messageId, "message");
    const result = await client.request(`/api/v1/project-message-acknowledge.php?project_id=${encodeURIComponent(profile.project_id)}&id=${encodeURIComponent(id)}`, { method: "POST", body: "{}" });
    return { profile: publicAgentProfile(profile), acknowledgement: result.data ?? result };
  }
}

function positiveId(value, label) {
  const normalized = String(value ?? "").trim();
  if (!/^[1-9][0-9]*$/.test(normalized)) throw new Error(`A valid Syndicatum ${label} ID is required.`);
  return normalized;
}

function projectFileId(value, label) {
  const normalized = String(value ?? "").trim().toLowerCase();
  if (!/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/.test(normalized)) {
    throw new Error(`A valid Syndicatum ${label} ID is required.`);
  }
  return normalized;
}

function optionalFolderId(value) {
  const normalized = String(value ?? "root").trim();
  return normalized === "" || normalized === "root" ? "root" : projectFileId(normalized, "folder");
}

function requiredText(value, label) {
  const normalized = String(value ?? "").trim();
  if (!normalized) throw new Error(`${label} is required.`);
  return normalized;
}

function normalizedIdempotencyKey(value) {
  const normalized = String(value || `project-file:${randomUUID()}`).trim();
  if (!/^[\x20-\x7e]{16,160}$/.test(normalized)) throw new Error("Idempotency key must contain 16–160 printable characters.");
  return normalized;
}

function stableUploadId(profileId, idempotencyKey) {
  const hex = createHash("sha256").update(`${profileId}\0${idempotencyKey}`).digest("hex").slice(0, 32).split("");
  hex[12] = "4";
  hex[16] = ["8", "9", "a", "b"][Number.parseInt(hex[16], 16) % 4];
  const value = hex.join("");
  return `${value.slice(0, 8)}-${value.slice(8, 12)}-${value.slice(12, 16)}-${value.slice(16, 20)}-${value.slice(20)}`;
}

function publicFilePayload(client, value) {
  if (Array.isArray(value)) return value.map(item => publicFilePayload(client, item));
  if (!value || typeof value !== "object") return value;
  return Object.fromEntries(Object.entries(value).filter(([key]) => !["storage_key", "path", "source_path", "destination_path"].includes(key))
    .map(([key, item]) => [key, key === "url" && typeof item === "string" ? client.publicUrl(`/${item.replace(/^\/+/, "")}`) : publicFilePayload(client, item)]));
}

function numericIds(values) {
  if (!Array.isArray(values)) return [];
  return [...new Set(values.map(value => Number(positiveId(value, "participant"))))];
}

function projectFileIds(values) {
  if (values === undefined || values === null) return [];
  if (!Array.isArray(values)) throw new Error("Attachment file IDs must be an array.");
  if (values.length > 20) throw new Error("A timeline message supports at most 20 attachments.");
  const ids = values.map(value => projectFileId(value, "attachment file"));
  if (new Set(ids).size !== ids.length) throw new Error("A file can be attached to a message only once.");
  return ids;
}

function nullablePositiveId(value, label) {
  return value === null || value === "" ? null : Number(positiveId(value, label));
}

function copyDefined(input, keys) {
  return Object.fromEntries(keys.filter(key => input[key] !== undefined).map(key => [key, input[key]]));
}

function clamp(value, minimum, maximum, fallback) {
  const parsed = Number(value);
  return Number.isFinite(parsed) ? Math.max(minimum, Math.min(maximum, Math.trunc(parsed))) : fallback;
}
