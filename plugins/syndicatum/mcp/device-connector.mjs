import { randomUUID } from "node:crypto";
import { ActivationConnector } from "./connector.mjs";
import { CodexDriver } from "./codex-driver.mjs";
import { StateStore } from "./state-store.mjs";

export class DeviceConnector {
  constructor({ config, syndicatum, bindings, log = console }) {
    Object.assign(this, { config, syndicatum, bindings, log }); this.stopped = false; this.sockets = new Set(); this.processors = new Map();
  }
  async start() {
    for (const binding of this.bindings) {
      const state = new StateStore(`${this.config.stateFile}.participant-${binding.participant_id}`); await state.load();
      const childConfig = { ...this.config, projectId: String(binding.project_id), participantId: String(binding.participant_id), codexThreadId: binding.conversation_id, workingDirectory: binding.working_directory };
      const processor = new ActivationConnector({ config: childConfig, syndicatum: { isAcknowledged: async () => false }, driver: new CodexDriver(childConfig), state, log: this.log });
      const key = String(binding.project_id); if (!this.processors.has(key)) this.processors.set(key, []); this.processors.get(key).push(processor);
    }
    await Promise.all([...this.processors.keys()].map(projectId => this.connectLoop(projectId)));
  }
  stop() { this.stopped = true; for (const socket of this.sockets) socket.close(1000, "Syndicatum plugin stopping"); for (const list of this.processors.values()) for (const processor of list) processor.stop(); }
  async connectLoop(projectId) {
    while (!this.stopped) {
      try { await this.connectOnce(projectId, await this.syndicatum.admission(projectId)); }
      catch (error) { if (!this.stopped) this.log.error(`Realtime project ${projectId} failed: ${safe(error)}`); }
      if (!this.stopped) await new Promise(resolve => setTimeout(resolve, this.config.reconnectDelayMs));
    }
  }
  connectOnce(projectId, admission) {
    if (!admission.enabled) return Promise.reject(new Error("PBB Realtime is disabled."));
    return new Promise((resolve, reject) => {
      const socket = new WebSocket(admission.websocket_url); this.sockets.add(socket);
      let authenticated = false, joined = false, settled = false;
      const fail = error => { if (!settled) { settled = true; reject(error); } };
      socket.addEventListener("message", event => {
        let envelope; try { envelope = JSON.parse(String(event.data)); } catch { return; }
        if (envelope?.phase === "system" && envelope.type === "session.awaiting-auth" && !authenticated) { socket.send(JSON.stringify({ namespace: "pbb.realtime.v1", phase: "request", type: "session.auth.request", id: `req_auth_${randomUUID()}`, payload: { token: admission.token }, meta: {} })); return; }
        if (["ack", "response"].includes(envelope?.phase) && String(envelope.type || "").startsWith("session.auth") && !joined) { authenticated = true; joined = true; socket.send(JSON.stringify({ namespace: "pbb.realtime.v1", phase: "request", type: "room.join.request", id: `req_join_${randomUUID()}`, room: admission.room, payload: {}, meta: {} })); return; }
        if (envelope?.phase === "error") { fail(new Error(envelope.payload?.message || envelope.payload?.code || "Realtime request rejected.")); socket.close(); return; }
        if (["ack", "response"].includes(envelope?.phase) && String(envelope.type || "").startsWith("room.join")) { this.log.info(`Listening in ${admission.room} for ${this.processors.get(String(projectId)).length} binding(s).`); return; }
        if (envelope?.phase === "event" && envelope.type === "syndicatum.message.created" && envelope.payload?.message) for (const processor of this.processors.get(String(projectId)) ?? []) processor.enqueue(envelope.payload.message);
      });
      socket.addEventListener("error", () => fail(new Error("PBB Realtime WebSocket error.")));
      socket.addEventListener("close", event => { this.sockets.delete(socket); if (!settled) { settled = true; this.stopped || event.code === 1000 ? resolve() : reject(new Error(`PBB Realtime closed with code ${event.code}.`)); } });
    });
  }
}
function safe(error) { return String(error?.message || error).replace(/[\r\n\t]+/g, " ").slice(0, 500); }
