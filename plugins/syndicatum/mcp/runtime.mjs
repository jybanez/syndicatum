import { watch } from "node:fs";
import { appendFile, mkdir } from "node:fs/promises";
import path from "node:path";
import { loadConfig, loadPendingLogin, resolveActivationConfig, saveConfig, saveDeviceConfig, savePendingLogin } from "./config.mjs";
import { ActivationConnector } from "./connector.mjs";
import { DeviceConnector } from "./device-connector.mjs";
import { CodexDriver, resolveCodexPath, runCommand } from "./codex-driver.mjs";
import { pluginPaths } from "./paths.mjs";
import { ListenerLock } from "./listener-lock.mjs";
import { StateStore } from "./state-store.mjs";
import { DeviceSyndicatumClient, SyndicatumClient } from "./syndicatum-client.mjs";
import { AuthorizationListener } from "./authorization-listener.mjs";
import { BackgroundServiceManager } from "./background-service-manager.mjs";
import { loadPairingState, savePairingState } from "./pairing-state.mjs";

export class PluginRuntime {
  constructor(env = process.env, { manageBackground = true, background = null } = {}) { this.env = env; this.manageBackground = manageBackground; this.background = background ?? new BackgroundServiceManager({ env }); this.connector = null; this.task = null; this.lock = null; this.pairing = null; this.pairingTask = null; this.configWatcher = null; this.reloadTimer = null; this.status = { state: "starting" }; }
  async start() {
    await this.ensureConfigWatcher();
    await this.stop();
    try {
      const local = await loadConfig(this.env);
      if (local.mode === "device" && this.manageBackground) {
        const background = await this.background.ensureRunning();
        if (background.supported) {
          this.status = { state: background.running && background.ownsListener ? "ready" : "starting", role: "background", background };
          return this.status;
        }
      }
      this.lock = new ListenerLock(pluginPaths(this.env).listenerLock);
      const ownership = await this.lock.acquire();
      if (!ownership.acquired) {
        this.lock = null;
        this.status = { state: "running", role: "standby", message: "Another Codex plugin host owns the device listener." };
        return this.status;
      }
      if (local.mode === "device") return await this.startDevice(local);
      const client = new SyndicatumClient(local);
      const config = await resolveActivationConfig(local, client);
      const codexPath = await resolveCodexPath(config, this.env);
      const probe = await runCommand(codexPath, ["queue", "--help"], { timeoutMs: 15000 });
      if (!/Queue a message for an existing session/i.test(probe.stdout)) throw new Error("This Codex release does not provide the required queue command.");
      const state = new StateStore(config.stateFile); await state.load();
      const logger = this.logger();
      this.connector = new ActivationConnector({ config, syndicatum: client, driver: new CodexDriver(config), state, log: logger });
      this.status = { state: "running", projectId: config.projectId, participantId: config.participantId, conversationId: config.codexThreadId, workingDirectory: config.workingDirectory, pending: state.pending().length };
      this.task = this.connector.start().catch(error => { this.status = { ...this.status, state: "error", error: String(error?.message || error) }; logger.error(this.status.error); });
    } catch (error) {
      if (this.lock) await this.lock.release();
      this.lock = null;
      this.status = { state: /not configured/i.test(String(error?.message || error)) ? "unconfigured" : "error", error: String(error?.message || error) };
    }
    return this.status;
  }
  async stop() { if (this.connector) this.connector.stop(); this.connector = null; this.task = null; if (this.lock) await this.lock.release(); this.lock = null; }
  async configure(input) {
    await saveConfig(input, this.env);
    return this.start();
  }
  async beginLogin(input) {
    const result = await DeviceSyndicatumClient.begin(input.syndicatumUrl, input.deviceName);
    const pending = { syndicatumUrl: result.syndicatum_url, deviceCode: result.device_code, userCode: result.user_code, verificationUrl: result.verification_url, expiresAt: result.expires_at, authorizationId: result.authorization_id, realtime: result.realtime };
    await savePendingLogin(pending, this.env);
    await savePairingState({ state: "waiting_for_authorization", authorizationId: result.authorization_id, userCode: result.user_code, verificationUrl: result.verification_url, expiresAt: result.expires_at }, this.env);
    if (this.pairing) this.pairing.stop();
    this.pairing = new AuthorizationListener({ pending, log: this.logger("pairing"), isCompleted: () => this.isPairingComplete(result.authorization_id), onAuthorized: async authorization => {
      await savePairingState({ state: "authorization_approved", authorizationId: result.authorization_id, expiresAt: result.expires_at }, this.env);
      const codexPath = await resolveCodexPath({}, this.env);
      await saveDeviceConfig({ syndicatumUrl: pending.syndicatumUrl, deviceId: authorization.device_id, token: authorization.access_token, codexPath }, this.env);
      await savePairingState({ state: "credential_exchanged", authorizationId: result.authorization_id, deviceId: authorization.device_id }, this.env);
      const background = this.manageBackground ? await this.background.ensureRunning() : null;
      await savePairingState({ state: "ready", authorizationId: result.authorization_id, deviceId: authorization.device_id, background }, this.env);
      this.status = { state: "ready", mode: "device", deviceId: authorization.device_id, background };
      this.scheduleReload();
    } });
    this.pairingTask = this.pairing.start().catch(async error => {
      const pairingError = String(error?.message || error);
      await savePairingState({ state: "error", authorizationId: result.authorization_id, error: pairingError }, this.env);
      this.status = { ...this.status, pairingState: "error", pairingError };
    });
    this.status = { ...this.status, pairingState: "waiting_for_authorization", pairingExpiresAt: result.expires_at };
    return { state: "authorization_required", userCode: result.user_code, verificationUrl: result.verification_url, expiresAt: result.expires_at };
  }
  async completeLogin() {
    const existing = await this.configuredDevice();
    if (existing) return { state: "ready", deviceId: existing.deviceId, restartRequired: false };
    const pending = await loadPendingLogin(this.env); const result = await DeviceSyndicatumClient.exchange(pending);
    if (result.status === "pending") return { state: "authorization_pending", userCode: pending.userCode, verificationUrl: pending.verificationUrl, expiresAt: pending.expiresAt };
    if (result.status !== "authorized") throw new Error("Syndicatum did not authorize this device.");
    await savePairingState({ state: "authorization_approved", authorizationId: pending.authorizationId, expiresAt: pending.expiresAt }, this.env);
    const codexPath = await resolveCodexPath({}, this.env);
    await saveDeviceConfig({ syndicatumUrl: pending.syndicatumUrl, deviceId: result.device_id, token: result.access_token, codexPath }, this.env);
    await savePairingState({ state: "credential_exchanged", authorizationId: pending.authorizationId, deviceId: result.device_id }, this.env);
    this.pairing?.stop();
    const background = this.manageBackground ? await this.background.ensureRunning() : null;
    await savePairingState({ state: "ready", authorizationId: pending.authorizationId, deviceId: result.device_id, background }, this.env);
    return { state: "ready", deviceId: result.device_id, background, restartRequired: false };
  }
  async configuredDevice() {
    try { const config = await loadConfig(this.env); return config.mode === "device" ? config : null; }
    catch (error) {
      if (error.code === "ENOENT" || /not configured/i.test(String(error?.message || error))) return null;
      throw error;
    }
  }
  async isPairingComplete(authorizationId) {
    if (await this.configuredDevice()) return true;
    const pairing = await loadPairingState(this.env);
    return pairing?.authorizationId === authorizationId && pairing?.state === "ready";
  }
  async currentStatus() {
    let config;
    try { config = await this.configuredDevice(); }
    catch (error) { return { state: "error", stage: "credential_load", error: String(error?.message || error), pairing: await loadPairingState(this.env) }; }
    const pairing = await loadPairingState(this.env);
    if (!config) return pairing ? { state: pairing.state, pairing } : this.status;
    this.pairing?.stop();
    const background = this.manageBackground ? await this.background.status() : null;
    const ready = !background?.supported || (background.running && background.ownsListener);
    return { state: ready ? "ready" : "starting", mode: "device", deviceId: config.deviceId, role: this.manageBackground ? "background" : this.status.role, background, pairing };
  }
  async startDevice(config) {
    const client = new DeviceSyndicatumClient(config); const result = await client.bindings(); const bindings = Array.isArray(result.bindings) ? result.bindings : [];
    for (const binding of bindings) {
      binding.working_directory = path.resolve(String(binding.working_directory || "")); await import("node:fs/promises").then(({ access }) => access(binding.working_directory));
      if (!String(binding.conversation_id || "").trim()) throw new Error(`Binding for ${binding.agent_name || binding.agent_id} has no Codex conversation.`);
    }
    const codexPath = await resolveCodexPath(config, this.env); const probe = await runCommand(codexPath, ["queue", "--help"], { timeoutMs: 15000 });
    if (!/Queue a message for an existing session/i.test(probe.stdout)) throw new Error("This Codex release does not provide the required queue command.");
      const logger = this.logger("listener"); this.connector = new DeviceConnector({ config: { ...config, codexPath }, syndicatum: client, bindings, log: logger });
    this.status = { state: "running", mode: "device", deviceId: config.deviceId, bindings: bindings.length, projects: new Set(bindings.map(item => String(item.project_id))).size };
    this.task = this.connector.start().catch(error => { this.status = { ...this.status, state: "error", error: String(error?.message || error) }; logger.error(this.status.error); });
    return this.status;
  }
  logger(role = this.manageBackground ? "mcp" : "background") {
    const file = pluginPaths(this.env).log;
    const write = async (level, message) => { await mkdir(path.dirname(file), { recursive: true }); await appendFile(file, `${new Date().toISOString()} ${level} pid=${process.pid} role=${role} ${String(message).replace(/[\r\n]+/g, " ")}\n`, "utf8"); };
    return { info: message => void write("INFO", message), error: message => void write("ERROR", message) };
  }
  async ensureConfigWatcher() {
    if (this.configWatcher) return;
    const files = pluginPaths(this.env);
    await mkdir(files.root, { recursive: true });
    try {
      this.configWatcher = watch(files.root, (_event, filename) => {
        if (String(filename) === path.basename(files.config)) {
          this.pairing?.stop();
          void this.configuredDevice().then(config => { if (config) this.status = { state: "configured", mode: "device", deviceId: config.deviceId }; });
        } else if (String(filename) === path.basename(files.pairingState)) {
          void loadPairingState(this.env).then(pairing => { if (pairing?.state === "ready") this.pairing?.stop(); }).catch(() => {});
        }
      });
    } catch (_error) { /* Status still re-reads persisted state if directory watching is unavailable. */ }
  }
  scheduleReload() {
    clearTimeout(this.reloadTimer);
    this.reloadTimer = setTimeout(() => void this.start(), 250);
  }
}
