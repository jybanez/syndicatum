import { randomUUID } from "node:crypto";
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

  async post(profileId, input = {}) {
    const { profile, client } = await this.context(profileId);
    const body = String(input.body || "").trim();
    if (!body) throw new Error("A timeline message body is required.");
    const idempotencyKey = String(input.idempotency_key || `profile:${profile.profile_id}:${randomUUID()}`).trim();
    const payload = {
      body,
      direct_participant_ids: numericIds(input.direct_participant_ids),
      mention_participant_ids: numericIds(input.mention_participant_ids),
      broadcast: input.broadcast === true,
      action_requested: input.action_requested === true,
      idempotency_key: idempotencyKey,
    };
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

function numericIds(values) {
  if (!Array.isArray(values)) return [];
  return [...new Set(values.map(value => Number(positiveId(value, "participant"))))];
}

function clamp(value, minimum, maximum, fallback) {
  const parsed = Number(value);
  return Number.isFinite(parsed) ? Math.max(minimum, Math.min(maximum, Math.trunc(parsed))) : fallback;
}
