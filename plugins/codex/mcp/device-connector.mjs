import { randomUUID } from "node:crypto";
import { ActivationConnector } from "./connector.mjs";
import { CodexDriver } from "./codex-driver.mjs";
import { StateStore } from "./state-store.mjs";
import { agentProfileId, loadAgentProfile } from "./agent-profile-store.mjs";
import { SyndicatumClient } from "./syndicatum-client.mjs";

export class DeviceConnector {
  constructor({ config, syndicatum, bindings, log = console, loadProfile = loadAgentProfile, identityClientFactory = options => new SyndicatumClient(options), driverFactory = options => new CodexDriver(options) }) {
    Object.assign(this, { config, syndicatum, bindings, log, loadProfile, identityClientFactory, driverFactory }); this.stopped = false; this.sockets = new Set(); this.processors = new Map();
  }
  async start() {
    // Protected credential reads can be comparatively slow on Windows. Build
    // every binding concurrently so one profile cannot hold every project's
    // realtime listener offline during startup.
    const initialized = await Promise.all(this.bindings.map(binding => this.initializeBinding(binding)));
    const recoveryPlans = [];
    for (const { binding, processor, identityClient, state } of initialized) {
      const key = String(binding.project_id); if (!this.processors.has(key)) this.processors.set(key, []); this.processors.get(key).push(processor);
      if (identityClient) recoveryPlans.push({ binding, processor });
      if (processor.canCoalesce() && (state.activeWake()?.messages.length ?? 0) > 1) processor.scheduleCoalescedCheck();
    }
    const listeners = Promise.all([...this.processors.keys()].map(projectId => this.connectLoop(projectId)));
    await Promise.all(recoveryPlans.map(async ({ binding, processor }) => {
      try {
        await processor.recoverAddressed("startup-recovery", { waitForQueue: false });
      } catch (error) {
        processor.syndicatum = { isAcknowledged: async () => false };
        processor.config.coalescingEnabled = false;
        this.log.info(`Wake coalescing and startup recovery are unavailable for agent ${binding.agent_id}; its local claimed profile could not be used (${safe(error)}).`);
      }
    }));
    await listeners;
  }
  async initializeBinding(binding) {
    const state = new StateStore(`${this.config.stateFile}.participant-${binding.participant_id}`); await state.load();
    const profileId = agentProfileId(this.config.syndicatumUrl, binding.project_id, binding.agent_id);
    let identityClient = null;
    try {
      const profile = await this.loadProfile(profileId);
      identityClient = this.identityClientFactory({ syndicatumUrl: profile.syndicatum_url, projectId: String(profile.project_id), participantId: String(profile.participant_id), token: profile.token });
    } catch (error) {
      this.log.info(`Wake coalescing and startup recovery are unavailable for agent ${binding.agent_id}; its local claimed profile could not be used (${safe(error)}).`);
    }
    const childConfig = { ...this.config, projectId: String(binding.project_id), participantId: String(binding.participant_id), agentId: String(binding.agent_id), profileId, codexThreadId: binding.conversation_id, workingDirectory: binding.working_directory, coalescingEnabled: Boolean(identityClient) };
    const processor = new ActivationConnector({ config: childConfig, syndicatum: identityClient ?? { isAcknowledged: async () => false }, driver: this.driverFactory(childConfig), state, log: this.log });
    return { binding, processor, identityClient, state };
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
        if (["ack", "response"].includes(envelope?.phase) && String(envelope.type || "").startsWith("room.join")) {
          const processors = this.processors.get(String(projectId)) ?? [];
          this.log.info(`Listening in ${admission.room} for ${processors.length} binding(s).`);
          // The listener may have been offline after the startup snapshot was
          // read. Reconcile once more only after room admission, so every
          // message is covered by either this snapshot or the live stream.
          for (const processor of processors) {
            void processor.recoverAddressed("post-join-recovery").catch(error =>
              this.log.error(`Post-join recovery failed for agent ${processor.config.agentId}: ${safe(error)}`));
          }
          return;
        }
        if (envelope?.phase === "event" && envelope.type === "syndicatum.message.created" && envelope.payload?.message) for (const processor of this.processors.get(String(projectId)) ?? []) processor.enqueue(envelope.payload.message);
      });
      socket.addEventListener("error", () => fail(new Error("PBB Realtime WebSocket error.")));
      socket.addEventListener("close", event => { this.sockets.delete(socket); if (!settled) { settled = true; this.stopped || event.code === 1000 ? resolve() : reject(new Error(`PBB Realtime closed with code ${event.code}.`)); } });
    });
  }
}
function safe(error) { return String(error?.message || error).replace(/[\r\n\t]+/g, " ").slice(0, 500); }
