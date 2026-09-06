import { randomUUID } from "node:crypto";

export class SyndicatumClient {
  constructor(config, fetchImpl = fetch) { this.config = config; this.fetch = fetchImpl; }
  async validateBinding() {
    const result = await this.request("/api/v1/projects.php");
    const project = (result.data ?? []).find(item => String(item.id) === this.config.projectId);
    if (!project) throw new Error("The token is not authorized for the configured Syndicatum project.");
    if (String(project.participant_id) !== this.config.participantId) throw new Error("The participant does not belong to this token and project.");
    return project;
  }
  async admission() { return this.request(`/api/v1/realtime-admission.php?project_id=${encodeURIComponent(this.config.projectId)}`).then(result => result.data ?? {}); }
  async activationBinding() { return this.request(`/api/v1/agent-activation-binding.php?project_id=${encodeURIComponent(this.config.projectId)}`).then(result => result.data ?? {}); }
  async addressedUnacknowledged() {
    const query = new URLSearchParams({ project_id: this.config.projectId, addressed_to: "me", acknowledged: "false", limit: "100" });
    return this.request(`/api/v1/project-messages.php?${query}`).then(result => result.data ?? []);
  }
  async isAcknowledged(messageId) {
    const result = await this.request(`/api/v1/project-message.php?project_id=${encodeURIComponent(this.config.projectId)}&id=${encodeURIComponent(messageId)}`);
    const message = result.data ?? result.message ?? result;
    return Array.isArray(message?.addressees) && message.addressees.some(entry => String(entry.participant_id ?? entry.id) === this.config.participantId && Boolean(entry.acknowledged_at));
  }
  async request(relativePath, options = {}) {
    const response = await this.fetch(new URL(relativePath, `${this.config.syndicatumUrl}/`), {
      ...options,
      headers: { Authorization: `Bearer ${this.config.token}`, Accept: "application/json", "Content-Type": "application/json", "X-Connector-Request-Id": randomUUID(), ...(options.headers ?? {}) },
    });
    const body = await response.json().catch(() => null);
    if (!response.ok) {
      const error = new Error(`Syndicatum request failed: ${body?.message || body?.code || `HTTP ${response.status}`}`);
      error.status = response.status;
      throw error;
    }
    return body ?? {};
  }
}

export class DeviceSyndicatumClient {
  constructor(config, fetchImpl = fetch) { this.config = config; this.fetch = fetchImpl; }
  async bindings() { return this.request("/api/v1/connector-bindings.php").then(result => result.data ?? {}); }
  async admission(projectId) { return this.request(`/api/v1/connector-realtime-admission.php?project_id=${encodeURIComponent(projectId)}`).then(result => result.data ?? {}); }
  async request(relativePath, options = {}) {
    const response = await this.fetch(new URL(relativePath, `${this.config.syndicatumUrl}/`), { ...options, headers: { Authorization: `Bearer ${this.config.token}`, Accept: "application/json", "Content-Type": "application/json", ...(options.headers ?? {}) } });
    const body = await response.json().catch(() => null);
    if (!response.ok) throw new Error(`Syndicatum request failed: ${body?.message || body?.code || `HTTP ${response.status}`}`);
    return body ?? {};
  }
  static async begin(syndicatumUrl, deviceName, fetchImpl = fetch) {
    const base = String(syndicatumUrl).trim().replace(/\/+$/, "");
    const response = await fetchImpl(new URL("/api/v1/connector-device-authorizations.php", `${base}/`), { method: "POST", headers: { Accept: "application/json", "Content-Type": "application/json" }, body: JSON.stringify({ device_name: deviceName, platform: process.platform }) });
    const body = await response.json().catch(() => null); if (!response.ok) throw new Error(body?.message || `HTTP ${response.status}`);
    const data = body.data ?? {}; return { ...data, verification_url: new URL(data.verification_uri, `${base}/`).href, syndicatum_url: base };
  }
  static async exchange(pending, fetchImpl = fetch) {
    const response = await fetchImpl(new URL("/api/v1/connector-device-token.php", `${pending.syndicatumUrl}/`), { method: "POST", headers: { Accept: "application/json", "Content-Type": "application/json" }, body: JSON.stringify({ device_code: pending.deviceCode }) });
    const body = await response.json().catch(() => null); if (!response.ok) throw new Error(body?.message || `HTTP ${response.status}`); return body.data ?? {};
  }
}
