import { access, mkdir, readFile, writeFile, rm } from "node:fs/promises";
import path from "node:path";
import { loadToken, storeToken } from "./secret-store.mjs";
import { pluginPaths } from "./paths.mjs";

export async function loadConfig(env = process.env) {
  const files = pluginPaths(env);
  const raw = JSON.parse(await readFile(files.config, "utf8"));
  if (raw.mode === "device") {
    if (!String(raw.syndicatumUrl ?? "").trim() || !String(raw.deviceId ?? "").trim()) throw new Error("Syndicatum device configuration is incomplete.");
    return Object.freeze({
      mode: "device", syndicatumUrl: String(raw.syndicatumUrl).replace(/\/+$/, ""), deviceId: String(raw.deviceId),
      codexPath: String(env.CODEX_CLI_PATH || raw.codexPath || "").trim() || null,
      reconnectDelayMs: clamp(raw.reconnectDelayMs, 1000, 60000, 5000),
      activationRetryLimit: clamp(raw.activationRetryLimit, 1, 20, 8), activationRetryBaseMs: 5000, activationRetryMaxMs: 60000,
      stateFile: files.state, token: await loadToken(files.credential, env),
    });
  }
  for (const key of ["syndicatumUrl", "projectId", "participantId"]) {
    if (!String(raw[key] ?? "").trim()) throw new Error(`Missing Syndicatum plugin configuration: ${key}`);
  }
  return Object.freeze({
    syndicatumUrl: String(raw.syndicatumUrl).replace(/\/+$/, ""),
    projectId: String(raw.projectId),
    participantId: String(raw.participantId),
    codexThreadId: null,
    workingDirectory: null,
    codexPath: String(env.CODEX_CLI_PATH || raw.codexPath || "").trim() || null,
    processExistingUnacknowledged: raw.processExistingUnacknowledged === true,
    reconnectDelayMs: clamp(raw.reconnectDelayMs, 1000, 60000, 5000),
    activationRetryLimit: clamp(raw.activationRetryLimit, 1, 20, 8),
    activationRetryBaseMs: clamp(raw.activationRetryBaseMs, 1000, 60000, 5000),
    activationRetryMaxMs: clamp(raw.activationRetryMaxMs, 5000, 300000, 60000),
    stateFile: files.state,
    credentialFile: files.credential,
    token: await loadToken(files.credential, env),
  });
}

export async function saveDeviceConfig(input, env = process.env) {
  const files = pluginPaths(env);
  const raw = { mode: "device", syndicatumUrl: String(input.syndicatumUrl).trim().replace(/\/+$/, ""), deviceId: String(input.deviceId).trim(), reconnectDelayMs: 5000, activationRetryLimit: 8 };
  if (!raw.syndicatumUrl || !raw.deviceId) throw new Error("Syndicatum device configuration is incomplete.");
  await mkdir(files.root, { recursive: true });
  await storeToken(files.credential, input.token);
  await writeFile(files.config, `${JSON.stringify(raw, null, 2)}\n`, { encoding: "utf8", mode: 0o600 });
  await Promise.all([rm(files.pendingLogin, { force: true }), rm(files.pendingCredential, { force: true })]);
  return loadConfig(env);
}

export async function savePendingLogin(input, env = process.env) {
  const files = pluginPaths(env); await mkdir(files.root, { recursive: true });
  await storeToken(files.pendingCredential, input.deviceCode);
  await writeFile(files.pendingLogin, `${JSON.stringify({ syndicatumUrl: input.syndicatumUrl, userCode: input.userCode, verificationUrl: input.verificationUrl, expiresAt: input.expiresAt, authorizationId: input.authorizationId, realtime: input.realtime }, null, 2)}\n`, { encoding: "utf8", mode: 0o600 });
}

export async function loadPendingLogin(env = process.env) {
  const files = pluginPaths(env); const metadata = JSON.parse(await readFile(files.pendingLogin, "utf8"));
  return { ...metadata, deviceCode: await loadToken(files.pendingCredential, { ...env, SYNDICATUM_AGENT_TOKEN: "" }) };
}

export async function saveConfig(input, env = process.env) {
  const files = pluginPaths(env);
  const raw = {
    syndicatumUrl: String(input.syndicatumUrl || "").trim().replace(/\/+$/, ""),
    projectId: String(input.projectId || "").trim(),
    participantId: String(input.participantId || "").trim(),
    processExistingUnacknowledged: false,
    reconnectDelayMs: 5000,
    activationRetryLimit: 8,
    activationRetryBaseMs: 5000,
    activationRetryMaxMs: 60000,
  };
  for (const key of ["syndicatumUrl", "projectId", "participantId"]) {
    if (!raw[key]) throw new Error(`Missing Syndicatum plugin configuration: ${key}`);
  }
  const url = new URL(raw.syndicatumUrl);
  if (url.protocol !== "https:" && url.hostname !== "localhost" && url.hostname !== "127.0.0.1") {
    throw new Error("Syndicatum must use HTTPS except during localhost development.");
  }
  await mkdir(files.root, { recursive: true });
  await storeToken(files.credential, input.token);
  await writeFile(files.config, `${JSON.stringify(raw, null, 2)}\n`, { encoding: "utf8", mode: 0o600 });
  return loadConfig(env);
}

export async function resolveActivationConfig(config, syndicatum) {
  const binding = await syndicatum.activationBinding();
  if (!binding.enabled) throw new Error("Conversation notifications are disabled for this Syndicatum agent.");
  const codexThreadId = String(binding.conversation_id ?? "").trim();
  const workingDirectory = String(binding.working_directory ?? "").trim();
  if (!codexThreadId || !workingDirectory) throw new Error("The Syndicatum agent activation binding is incomplete.");
  const resolved = path.resolve(workingDirectory);
  await access(resolved);
  return Object.freeze({ ...config, codexThreadId, workingDirectory: resolved, activationBindingSource: "syndicatum" });
}

function clamp(value, minimum, maximum, fallback) {
  const parsed = Number(value);
  return Number.isFinite(parsed) ? Math.max(minimum, Math.min(maximum, parsed)) : fallback;
}
