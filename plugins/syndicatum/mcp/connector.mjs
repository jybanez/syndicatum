import { randomUUID } from "node:crypto";

export class ActivationConnector {
  constructor({ config, syndicatum, driver, state, log = console }) {
    Object.assign(this, { config, syndicatum, driver, state, log });
    this.queue = Promise.resolve(); this.stopped = false; this.socket = null;
    this.retryAttempts = new Map(); this.retryTimers = new Map();
  }
  async start() {
    await this.state.load();
    const project = await this.syndicatum.validateBinding();
    this.log.info(`Bound participant ${this.config.participantId} to ${project.name || `project ${this.config.projectId}`}.`);
    for (const message of this.state.pending()) this.enqueue(message, "pending-recovery");
    if (this.config.processExistingUnacknowledged) {
      for (const message of [...await this.syndicatum.addressedUnacknowledged()].reverse()) this.enqueue(message, "startup-recovery");
    }
    await this.connectLoop();
  }
  stop() {
    this.stopped = true;
    for (const timer of this.retryTimers.values()) clearTimeout(timer);
    this.retryTimers.clear();
    this.socket?.close(1000, "Syndicatum plugin stopping");
  }
  enqueue(message, source = "realtime") {
    this.queue = this.queue.then(() => this.handleMessage(message, source)).catch(error => {
      this.log.error(`Message processing failed: ${safe(error)}`); this.scheduleRetry(message, error); return { status: "retry-scheduled" };
    });
    return this.queue;
  }
  async handleMessage(message, source) {
    if (!isAddressedTo(message, this.config.participantId)) { await this.state.observeSequence(message); return { status: "ignored", reason: "not-addressed" }; }
    if (isSentBy(message, this.config.participantId)) return { status: "ignored", reason: "self-authored" };
    if (this.state.has(message.id)) return { status: "ignored", reason: "duplicate" };
    if (await this.syndicatum.isAcknowledged(message.id)) {
      await this.state.markProcessed(message); this.clearRetry(message.id); return { status: "ignored", reason: "already-acknowledged" };
    }
    await this.state.markPending(message);
    this.log.info(`Activating Codex for Syndicatum message ${message.id} (${source}).`);
    await this.driver.activate(message);
    await this.state.markProcessed(message); this.clearRetry(message.id);
    this.log.info(`Delivered Syndicatum notification ${message.id} to the linked conversation.`);
    return { status: "notified" };
  }
  scheduleRetry(message, error) {
    const id = String(message?.id ?? "").trim();
    if (this.stopped || !id || this.retryTimers.has(id)) return;
    const attempt = (this.retryAttempts.get(id) ?? 0) + 1;
    const busy = /thread-store conflict|thread is busy/i.test(String(error?.message || error));
    if (!busy && attempt > this.config.activationRetryLimit) { this.log.error(`Notification ${id} reached its retry limit.`); return; }
    this.retryAttempts.set(id, attempt);
    const wait = Math.min(this.config.activationRetryMaxMs, this.config.activationRetryBaseMs * (2 ** (attempt - 1)));
    const timer = setTimeout(() => { this.retryTimers.delete(id); this.enqueue(message, `retry-${attempt}`); }, wait);
    this.retryTimers.set(id, timer);
  }
  clearRetry(id) {
    const key = String(id); const timer = this.retryTimers.get(key); if (timer) clearTimeout(timer);
    this.retryTimers.delete(key); this.retryAttempts.delete(key);
  }
  async connectLoop() {
    while (!this.stopped) {
      try { await this.connectOnce(await this.syndicatum.admission()); }
      catch (error) { if (!this.stopped) this.log.error(`Realtime connection failed: ${safe(error)}`); }
      if (!this.stopped) await new Promise(resolve => setTimeout(resolve, this.config.reconnectDelayMs));
    }
  }
  async connectOnce(admission) {
    if (!admission.enabled) throw new Error("PBB Realtime is not enabled for this Syndicatum installation.");
    await new Promise((resolve, reject) => {
      const socket = new WebSocket(admission.websocket_url); this.socket = socket;
      let authenticated = false, joined = false, settled = false;
      const fail = error => { if (!settled) { settled = true; reject(error); } };
      socket.addEventListener("message", event => {
        let envelope; try { envelope = JSON.parse(String(event.data)); } catch { return; }
        if (envelope?.phase === "system" && envelope.type === "session.awaiting-auth" && !authenticated) {
          socket.send(JSON.stringify({ namespace: "pbb.realtime.v1", phase: "request", type: "session.auth.request", id: `req_auth_${randomUUID()}`, payload: { token: admission.token }, meta: {} })); return;
        }
        if (["ack", "response"].includes(envelope?.phase) && String(envelope.type || "").startsWith("session.auth") && !joined) {
          authenticated = true; joined = true;
          socket.send(JSON.stringify({ namespace: "pbb.realtime.v1", phase: "request", type: "room.join.request", id: `req_join_${randomUUID()}`, room: admission.room, payload: {}, meta: {} })); return;
        }
        if (envelope?.phase === "error") { fail(new Error(envelope.payload?.message || envelope.payload?.code || "Realtime request rejected.")); socket.close(); return; }
        if (["ack", "response"].includes(envelope?.phase) && String(envelope.type || "").startsWith("room.join")) { this.log.info(`Listening in ${admission.room}.`); return; }
        if (envelope?.phase === "event" && envelope.type === "syndicatum.message.created" && envelope.payload?.message) this.enqueue(envelope.payload.message);
      });
      socket.addEventListener("error", () => fail(new Error("PBB Realtime WebSocket error.")));
      socket.addEventListener("close", event => { this.socket = null; if (!settled) { settled = true; this.stopped || event.code === 1000 ? resolve() : reject(new Error(`PBB Realtime closed with code ${event.code}.`)); } });
    });
  }
}

export function isAddressedTo(message, participantId) { return Array.isArray(message?.addressees) && message.addressees.some(entry => String(entry.participant_id ?? entry.id) === String(participantId)); }
export function isSentBy(message, participantId) { return String(message?.sender?.id ?? message?.sender?.participant_id ?? "") === String(participantId); }
function safe(error) { return String(error?.message || error).replace(/[\r\n\t]+/g, " ").slice(0, 500); }
