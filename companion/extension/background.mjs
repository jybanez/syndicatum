import { bindingAcceptsMessage, deliveryKey, normalizeBaseUrl, normalizeDiscussionUrl, notificationFor, recoveryItem } from "./core.mjs";

const STATE_KEY = "syndicatumCompanion";
const RETRY_ALARM = "syndicatum-retry";
const SYNC_ALARM = "syndicatum-sync";
const sockets = new Map();
let running = null;
let queueWrites = Promise.resolve();
let drainRunning = null;

const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
const uuid = () => crypto.randomUUID();
async function state() { return (await chrome.storage.local.get(STATE_KEY))[STATE_KEY] || {}; }
async function save(patch) { const current = await state(); const next = { ...current, ...patch }; await chrome.storage.local.set({ [STATE_KEY]: next }); return next; }

async function api(path, options = {}) {
  const current = await state();
  if (!current.baseUrl) throw new Error("Syndicatum is not connected.");
  const headers = { Accept: "application/json", ...(options.body ? { "Content-Type": "application/json" } : {}), ...(options.headers || {}) };
  if (current.accessToken) headers.Authorization = `Bearer ${current.accessToken}`;
  const response = await fetch(`${current.baseUrl}${path}`, { ...options, headers });
  const result = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(result.message || result.code || `Syndicatum returned HTTP ${response.status}.`);
  return result.data ?? result;
}

async function beginConnection(baseUrl) {
  baseUrl = normalizeBaseUrl(baseUrl);
  await save({ baseUrl, status: "authorizing", lastError: null });
  const data = await api("/api/v1/connector-device-authorizations.php", { method: "POST", body: JSON.stringify({ device_name: "Syndicatum browser companion", platform: "chrome-extension" }) });
  await save({ pending: { deviceCode: data.device_code, userCode: data.user_code, expiresAt: data.expires_at }, status: "authorizing" });
  await chrome.tabs.create({ url: new URL(data.verification_uri, `${baseUrl}/`).href, active: true });
  setTimeout(() => void pollAuthorization(), 3000);
  await chrome.alarms.create(RETRY_ALARM, { delayInMinutes: 0.5 });
  return publicStatus();
}

async function pollAuthorization() {
  const current = await state();
  if (!current.pending?.deviceCode || current.accessToken) return;
  if (Date.parse(current.pending.expiresAt) <= Date.now()) { await save({ pending: null, status: "disconnected", lastError: "Authorization expired." }); return; }
  try {
    const result = await api("/api/v1/connector-device-token.php", { method: "POST", body: JSON.stringify({ device_code: current.pending.deviceCode }) });
    if (result.status === "authorized") {
      await save({ accessToken: result.access_token, deviceId: result.device_id, tokenExpiresAt: result.expires_at, pending: null, status: "connected", lastError: null });
      setTimeout(() => void start(), 0);
    } else {
      setTimeout(() => void pollAuthorization(), 3000);
      await chrome.alarms.create(RETRY_ALARM, { delayInMinutes: 0.5 });
    }
  } catch (error) {
    await save({ lastError: String(error?.message || error) });
    setTimeout(() => void pollAuthorization(), 5000);
    await chrome.alarms.create(RETRY_ALARM, { delayInMinutes: 0.5 });
  }
}

async function refreshBindings() {
  const bindings = await api("/api/v1/connector-bindings.php?provider=chatgpt");
  const normalized = (Array.isArray(bindings) ? bindings : []).map(binding => ({ ...binding, provider: "chatgpt", conversation_id: normalizeDiscussionUrl(binding.conversation_id, "chatgpt") }));
  await save({ bindings: normalized, status: "connected", lastSyncAt: new Date().toISOString(), lastError: null });
  connectRealtime(normalized);
  return normalized;
}

async function recover(bindings) {
  const items = await api("/api/v1/connector-pending-notifications.php?provider=chatgpt&limit=200");
  const byBinding = new Map(bindings.map(binding => [`${binding.project_id}:${binding.agent_id}`, binding]));
  for (const item of Array.isArray(items) ? items : []) {
    const binding = byBinding.get(`${item.project_id}:${item.agent_id}`);
    if (binding) await enqueue(recoveryItem(binding, item.message));
  }
}

async function enqueue(item) {
  queueWrites = queueWrites.then(async () => {
    const current = await state();
    const key = deliveryKey(item);
    if (current.delivered?.[key]) return;
    const queue = { ...(current.queue || {}), [key]: { ...item, attempts: current.queue?.[key]?.attempts || 0, queuedAt: current.queue?.[key]?.queuedAt || new Date().toISOString() } };
    await save({ queue });
  });
  await queueWrites;
  return drain();
}

async function drain() {
  if (drainRunning) return drainRunning;
  drainRunning = (async () => {
    while (true) {
      const current = await state();
      const entry = Object.entries(current.queue || {})[0];
      if (!entry) return;
      const [key, item] = entry;
      try {
        if (!item.browserDeliveredAt) {
          const result = await deliver(item);
          if (!result?.ok) throw Object.assign(new Error(result?.code || "Delivery failed."), { retryable: result?.retryable !== false });
          const afterBrowserDelivery = await state();
          const stagedQueue = { ...(afterBrowserDelivery.queue || {}) };
          stagedQueue[key] = { ...stagedQueue[key], browserDeliveredAt: new Date().toISOString() };
          await save({ queue: stagedQueue });
        }
        await api("/api/v1/connector-notification-deliveries.php", { method: "POST", body: JSON.stringify({ provider: item.provider, project_id: item.project_id, agent_id: item.agent_id, message_id: item.message.id }) });
        const latest = await state();
        const queue = { ...(latest.queue || {}) }; delete queue[key];
        const delivered = { ...(latest.delivered || {}), [key]: new Date().toISOString() };
        const entries = Object.entries(delivered).slice(-1000);
        await save({ queue, delivered: Object.fromEntries(entries), lastDeliveryAt: delivered[key], lastError: null });
      } catch (error) {
        const latest = await state();
        const queue = { ...(latest.queue || {}) };
        if (queue[key]) queue[key] = { ...queue[key], attempts: Number(queue[key].attempts || 0) + 1, lastError: String(error?.message || error) };
        await save({ queue, lastError: `Delivery pending: ${String(error?.message || error)}` });
        await chrome.alarms.create(RETRY_ALARM, { delayInMinutes: 1 });
        return;
      }
    }
  })().finally(() => { drainRunning = null; });
  return drainRunning;
}

async function deliver(item) {
  const url = normalizeDiscussionUrl(item.conversation_id, item.provider);
  const tabs = await chrome.tabs.query({ url: `${url}*` });
  let tab = tabs[0];
  if (!tab) tab = await chrome.tabs.create({ url, active: false });
  if (tab.status !== "complete") {
    for (let index = 0; index < 40; index++) { await delay(250); tab = await chrome.tabs.get(tab.id); if (tab.status === "complete") break; }
  }
  try {
    return await chrome.tabs.sendMessage(tab.id, { type: "syndicatum.provider.deliver", provider: item.provider, text: notificationFor(item, item.message) });
  } catch {
    await delay(750);
    return chrome.tabs.sendMessage(tab.id, { type: "syndicatum.provider.deliver", provider: item.provider, text: notificationFor(item, item.message) });
  }
}

function connectRealtime(bindings) {
  const projects = new Set(bindings.map(binding => String(binding.project_id)));
  for (const [projectId, socket] of sockets) if (!projects.has(projectId)) { socket.close(1000, "Binding removed"); sockets.delete(projectId); }
  for (const projectId of projects) if (!sockets.has(projectId)) void connectProject(projectId);
}

async function connectProject(projectId) {
  try {
    const admission = await api(`/api/v1/connector-realtime-admission.php?project_id=${encodeURIComponent(projectId)}`);
    if (!admission.enabled) return;
    const socket = new WebSocket(admission.websocket_url); sockets.set(projectId, socket);
    let authenticated = false; let joined = false;
    socket.onmessage = async event => {
      let envelope; try { envelope = JSON.parse(event.data); } catch { return; }
      if (envelope?.phase === "system" && envelope.type === "session.awaiting-auth" && !authenticated) {
        socket.send(JSON.stringify({ namespace: "pbb.realtime.v1", phase: "request", type: "session.auth.request", id: `req_auth_${uuid()}`, payload: { token: admission.token }, meta: {} })); return;
      }
      if (["ack", "response"].includes(envelope?.phase) && String(envelope.type || "").startsWith("session.auth") && !joined) {
        authenticated = true; joined = true;
        socket.send(JSON.stringify({ namespace: "pbb.realtime.v1", phase: "request", type: "room.join.request", id: `req_join_${uuid()}`, room: admission.room, payload: {}, meta: {} })); return;
      }
      if (envelope?.phase === "event" && envelope.type === "syndicatum.message.created" && envelope.payload?.message) {
        const current = await state();
        for (const binding of (current.bindings || []).filter(entry => String(entry.project_id) === projectId)) {
          if (bindingAcceptsMessage(binding, envelope.payload.message)) await enqueue(recoveryItem(binding, envelope.payload.message));
        }
      }
    };
    socket.onclose = () => { sockets.delete(projectId); setTimeout(() => void start(), 5000); };
    socket.onerror = () => socket.close();
  } catch (error) { await save({ lastError: `Realtime unavailable: ${String(error?.message || error)}` }); }
}

async function start() {
  if (running) return running;
  running = (async () => {
    const current = await state();
    if (!current.accessToken) { if (current.pending) await pollAuthorization(); return; }
    const bindings = await refreshBindings();
    await recover(bindings);
    await drain();
    await chrome.alarms.create(SYNC_ALARM, { periodInMinutes: 5 });
  })().catch(async error => save({ lastError: String(error?.message || error) })).finally(() => { running = null; });
  return running;
}

async function disconnect() {
  for (const socket of sockets.values()) socket.close(1000, "Disconnected");
  sockets.clear();
  await chrome.alarms.clearAll();
  await chrome.storage.local.remove(STATE_KEY);
}

async function publicStatus() {
  const current = await state();
  return { status: current.status || "disconnected", baseUrl: current.baseUrl || "https://chatviewer.pbb.ph", userCode: current.pending?.userCode || null, bindingCount: current.bindings?.length || 0, queuedCount: Object.keys(current.queue || {}).length, lastSyncAt: current.lastSyncAt || null, lastDeliveryAt: current.lastDeliveryAt || null, lastError: current.lastError || null };
}

chrome.runtime.onMessage.addListener((request, sender, respond) => {
  const action = request?.type;
  const operation = action === "syndicatum.connect" ? beginConnection(request.baseUrl)
    : action === "syndicatum.disconnect" ? disconnect().then(publicStatus)
    : action === "syndicatum.refresh" ? start().then(publicStatus)
    : action === "syndicatum.status" ? publicStatus()
    : null;
  if (!operation) return false;
  operation.then(data => respond({ ok: true, data })).catch(error => respond({ ok: false, error: String(error?.message || error) }));
  return true;
});
chrome.runtime.onInstalled.addListener(() => void start());
chrome.runtime.onStartup.addListener(() => void start());
chrome.alarms.onAlarm.addListener(alarm => { if (alarm.name === RETRY_ALARM || alarm.name === SYNC_ALARM) void start(); });
void start();
