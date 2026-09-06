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
    const url = new URL(relativePath, `${this.config.syndicatumUrl}/`);
    const response = await requestFetch(this.fetch, url, {
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
    const url = new URL(relativePath, `${this.config.syndicatumUrl}/`);
    const response = await requestFetch(this.fetch, url, { ...options, headers: { Authorization: `Bearer ${this.config.token}`, Accept: "application/json", "Content-Type": "application/json", ...(options.headers ?? {}) } });
    const body = await response.json().catch(() => null);
    if (!response.ok) {
      const error = new Error(`Syndicatum request failed: ${body?.message || body?.code || `HTTP ${response.status}`}`);
      error.status = response.status;
      throw error;
    }
    return body ?? {};
  }
  static async begin(syndicatumUrl, deviceName, fetchImpl = fetch) {
    const base = String(syndicatumUrl).trim().replace(/\/+$/, "");
    const url = new URL("/api/v1/connector-device-authorizations.php", `${base}/`);
    const response = await requestFetch(fetchImpl, url, { method: "POST", headers: { Accept: "application/json", "Content-Type": "application/json" }, body: JSON.stringify({ device_name: deviceName, platform: process.platform }) });
    const body = await response.json().catch(() => null); if (!response.ok) throw new Error(body?.message || `HTTP ${response.status}`);
    const data = body.data ?? {}; return { ...data, verification_url: new URL(data.verification_uri, `${base}/`).href, syndicatum_url: base };
  }
  static async exchange(pending, fetchImpl = fetch) {
    const url = new URL("/api/v1/connector-device-token.php", `${pending.syndicatumUrl}/`);
    const response = await requestFetch(fetchImpl, url, { method: "POST", headers: { Accept: "application/json", "Content-Type": "application/json" }, body: JSON.stringify({ device_code: pending.deviceCode }) });
    const body = await response.json().catch(() => null); if (!response.ok) throw new Error(body?.message || `HTTP ${response.status}`); return body.data ?? {};
  }
}

async function requestFetch(fetchImpl, url, options) {
  try { return await fetchImpl(url, options); }
  catch (error) {
    const cause = String(error?.cause?.message || error?.message || error);
    const code = String(error?.cause?.code || error?.code || "").toUpperCase();
    const category = /CERT|TLS|SSL/.test(`${code} ${cause}`) ? "tls"
      : /ENOTFOUND|EAI_AGAIN|DNS/.test(`${code} ${cause}`) ? "dns"
        : /TIMEOUT|TIMEDOUT|ABORT/.test(`${code} ${cause}`) ? "timeout" : "network";
    const failure = new Error(`Syndicatum ${category} error at ${url.hostname}: ${cause}`, { cause: error });
    Object.assign(failure, { category, hostname: url.hostname, retryable: category !== "tls" });
    throw failure;
  }
}
