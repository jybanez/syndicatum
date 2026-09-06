import { randomUUID } from "node:crypto";
import { DeviceSyndicatumClient } from "./syndicatum-client.mjs";

export class AuthorizationListener {
  constructor({ pending, onAuthorized, exchange = value => DeviceSyndicatumClient.exchange(value), isCompleted = async () => false, reconnectDelayMs = 2000, reconciliationDelayMs = 8000, maxReconciliationAttempts = 2, log = console }) {
    Object.assign(this, { pending, onAuthorized, exchange, isCompleted, reconnectDelayMs, reconciliationDelayMs, maxReconciliationAttempts, log });
    this.stopped = false; this.socket = null; this.completed = false; this.reconciliationAttempts = 0; this.retryTimer = null; this.retryResolve = null;
  }
  async start() {
    const expiresAt = new Date(this.pending.expiresAt).getTime();
    while (!this.stopped && Date.now() < expiresAt) {
      if (await this.isCompleted()) { this.completed = true; this.stop(); break; }
      try { await this.connectOnce(); }
      catch (error) {
        let recovered = false;
        try { if (!this.stopped) recovered = await this.reconcile(); }
        catch (reconciliationError) { if (!this.stopped) this.log.error(`Authorization reconciliation failed: ${safe(reconciliationError)}`); }
        if (recovered) break;
        if (!this.stopped) this.log.error(`Authorization listener failed: ${safe(error)}`);
      }
      if (!this.stopped && Date.now() < expiresAt) await this.delay(this.reconnectDelayMs);
    }
    if (!this.completed && !this.stopped) throw new Error("The Syndicatum device authorization request expired.");
  }
  stop() { this.stopped = true; clearTimeout(this.retryTimer); this.retryTimer = null; this.retryResolve?.(); this.retryResolve = null; if (this.socket) this.socket.close(1000, "Authorization listener stopping"); }
  delay(ms) { return new Promise(resolve => { this.retryResolve = resolve; this.retryTimer = setTimeout(() => { this.retryTimer = null; this.retryResolve = null; resolve(); }, ms); }); }
  async reconcile() {
    if (this.reconciliationAttempts >= this.maxReconciliationAttempts || this.stopped) return false;
    this.reconciliationAttempts += 1;
    if (this.reconciliationAttempts > 1) await this.delay(this.reconciliationDelayMs);
    if (this.stopped || await this.isCompleted()) { this.completed = true; this.stop(); return true; }
    const result = await this.exchange(this.pending);
    if (result.status !== "authorized") return false;
    await this.finishAuthorization(result);
    return true;
  }
  async finishAuthorization(result) {
    if (this.completed) return;
    this.completed = true; this.stopped = true;
    await this.onAuthorized(result);
    if (this.socket) this.socket.close(1000, "Authorization completed");
  }
  connectOnce() {
    const admission = this.pending.realtime;
    if (!admission?.enabled || !admission?.token || !admission?.room || !admission?.websocket_url) return Promise.reject(new Error("Authorization Realtime admission is unavailable."));
    return new Promise((resolve, reject) => {
      const socket = new WebSocket(admission.websocket_url); this.socket = socket;
      let authenticated = false, joined = false, settled = false;
      const finish = error => { if (settled) return; settled = true; this.socket = null; error ? reject(error) : resolve(); };
      const exchange = async () => {
        const result = await this.exchange(this.pending);
        if (result.status !== "authorized") return false;
        await this.finishAuthorization(result);
        return true;
      };
      socket.addEventListener("message", event => {
        let envelope; try { envelope = JSON.parse(String(event.data)); } catch { return; }
        if (envelope?.phase === "system" && envelope.type === "session.awaiting-auth" && !authenticated) {
          socket.send(JSON.stringify({ namespace: "pbb.realtime.v1", phase: "request", type: "session.auth.request", id: `req_auth_${randomUUID()}`, payload: { token: admission.token }, meta: {} })); return;
        }
        if (["ack", "response"].includes(envelope?.phase) && String(envelope.type || "").startsWith("session.auth") && !joined) {
          authenticated = true; joined = true;
          socket.send(JSON.stringify({ namespace: "pbb.realtime.v1", phase: "request", type: "room.join.request", id: `req_join_${randomUUID()}`, room: admission.room, payload: {}, meta: {} })); return;
        }
        if (envelope?.phase === "error") { finish(new Error(envelope.payload?.message || envelope.payload?.code || "Realtime authorization request rejected.")); socket.close(); return; }
        if (["ack", "response"].includes(envelope?.phase) && String(envelope.type || "").startsWith("room.join")) {
          this.log.info(`Waiting for device approval in ${admission.room}.`);
          void exchange().catch(error => { finish(error); socket.close(); }); return;
        }
        if (envelope?.phase === "event" && envelope.type === "connector.authorization.approved"
          && String(envelope.payload?.authorization_id || "") === String(this.pending.authorizationId || "")) {
          void exchange().catch(error => { finish(error); socket.close(); });
        }
      });
      socket.addEventListener("error", () => { finish(new Error("Authorization Realtime WebSocket error.")); socket.close(); });
      socket.addEventListener("close", event => finish(this.stopped || event.code === 1000 ? null : new Error(`Authorization Realtime closed with code ${event.code}.`)));
    });
  }
}

function safe(error) { return String(error?.message || error).replace(/[\r\n\t]+/g, " ").slice(0, 500); }
