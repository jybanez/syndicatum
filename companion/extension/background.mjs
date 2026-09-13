import { bindingAcceptsMessage, bindingsFromResponse, deliveryKey, normalizeBaseUrl, normalizeDiscussionUrl, notificationFor, providerForDiscussionUrl, PROVIDERS, recoveryItem, selectDeliveryTab } from "./core.mjs";

const STATE_KEY = "syndicatumCompanion";
const RETRY_ALARM = "syndicatum-retry";
const SYNC_ALARM = "syndicatum-sync";
const sockets = new Map();
const heartbeatTimers = new Map();
let running = null;
let queueWrites = Promise.resolve();
let drainRunning = null;

const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
const uuid = () => crypto.randomUUID();
async function state() { return (await chrome.storage.local.get(STATE_KEY))[STATE_KEY] || {}; }
async function save(patch) { const current = await state(); const next = { ...current, ...patch }; await chrome.storage.local.set({ [STATE_KEY]: next }); return next; }

function stopHeartbeat(projectId) {
  const timer = heartbeatTimers.get(projectId);
  if (timer) clearInterval(timer);
  heartbeatTimers.delete(projectId);
}

function startHeartbeat(projectId, socket) {
  stopHeartbeat(projectId);
  const sendHealth = () => {
    if (sockets.get(projectId) !== socket || socket.readyState !== WebSocket.OPEN) return;
    socket.send(JSON.stringify({
      namespace: "pbb.realtime.v1",
      phase: "request",
      type: "session.health.request",
      id: `req_health_${uuid()}`,
      payload: { client_time: new Date().toISOString() },
      meta: {},
    }));
  };
  sendHealth();
  heartbeatTimers.set(projectId, setInterval(sendHealth, 20000));
}

async function recordDeliveryDiagnostic(diagnostic) {
  const current = await state();
  const history = [...(current.deliveryHistory || []), diagnostic].slice(-25);
  await save({ deliveryHistory: history, lastDeliveryDiagnostic: diagnostic });
}

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
  const providerBindings = await Promise.all(Object.keys(PROVIDERS).map(async provider => {
    const response = await api(`/api/v1/connector-bindings.php?provider=${encodeURIComponent(provider)}`);
    return bindingsFromResponse(response).map(binding => ({ ...binding, provider, conversation_id: normalizeDiscussionUrl(binding.conversation_id, provider) }));
  }));
  const normalized = providerBindings.flat();
  await save({ bindings: normalized, status: "connected", lastSyncAt: new Date().toISOString(), lastError: null });
  connectRealtime(normalized);
  return normalized;
}

async function bindActiveDiscussion(bindingCode) {
  const code = String(bindingCode || "").trim();
  if (!code) throw new Error("Enter a discussion binding code.");
  const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
  if (!tab?.url) throw new Error("The active browser tab could not be read.");
  const detected = providerForDiscussionUrl(tab.url);
  const result = await api("/api/v1/connector-discussion-bindings.php", {
    method: "POST",
    body: JSON.stringify({ binding_code: code, provider: detected.provider, discussion_reference: detected.discussionUrl }),
  });
  const bindings = await refreshBindings();
  await recover(bindings);
  await drain();
  await save({ lastBindingMessage: `${PROVIDERS[detected.provider].label} discussion bound to ${result.agent_name || "the selected agent"}.`, lastError: null });
  return publicStatus();
}

async function recover(bindings) {
  const byBinding = new Map(bindings.map(binding => [`${binding.project_id}:${binding.agent_id}`, binding]));
  for (const provider of Object.keys(PROVIDERS)) {
    const items = await api(`/api/v1/connector-pending-notifications.php?provider=${encodeURIComponent(provider)}&limit=200`);
    for (const item of Array.isArray(items) ? items : []) {
      const binding = byBinding.get(`${item.project_id}:${item.agent_id}`);
      if (binding && binding.provider === provider) await enqueue(recoveryItem(binding, item.message));
    }
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
      const startedAt = new Date().toISOString();
      try {
        let result = null;
        if (item.provider === "gemini") {
          result = await deliver(item);
          if (!result?.ok) throw Object.assign(new Error(result?.code || "Delivery failed."), { retryable: result?.retryable !== false });
          const response = String(result.responseText || "").trim();
          if (!response) throw new Error("Gemini completed without a capturable response.");
          await api("/api/v1/connector-agent-replies.php", {
            method: "POST",
            body: JSON.stringify({ provider: item.provider, project_id: item.project_id, agent_id: item.agent_id, message_id: item.message.id, response }),
          });
        } else if (!item.browserDeliveredAt) {
          const result = await deliver(item);
          if (!result?.ok) throw Object.assign(new Error(result?.code || "Delivery failed."), { retryable: result?.retryable !== false });
          const afterBrowserDelivery = await state();
          const stagedQueue = { ...(afterBrowserDelivery.queue || {}) };
          stagedQueue[key] = { ...stagedQueue[key], browserDeliveredAt: new Date().toISOString(), browserDelivery: result };
          await save({ queue: stagedQueue });
        }
        if (item.provider !== "gemini") {
          await api("/api/v1/connector-notification-deliveries.php", { method: "POST", body: JSON.stringify({ provider: item.provider, project_id: item.project_id, agent_id: item.agent_id, message_id: item.message.id }) });
        }
        const latest = await state();
        const queue = { ...(latest.queue || {}) }; delete queue[key];
        const delivered = { ...(latest.delivered || {}), [key]: new Date().toISOString() };
        const entries = Object.entries(delivered).slice(-1000);
        await save({ queue, delivered: Object.fromEntries(entries), lastDeliveryAt: delivered[key], lastError: null });
        await recordDeliveryDiagnostic({
          key,
          provider: item.provider,
          projectId: item.project_id,
          agentId: item.agent_id,
          messageId: item.message.id,
          queuedAt: item.queuedAt || null,
          startedAt,
          completedAt: delivered[key],
          attempts: Number(item.attempts || 0) + 1,
          outcome: item.provider === "gemini" ? "replied" : "delivered",
          ...deliveryMetadata(result || latest.queue?.[key]?.browserDelivery || {}),
        });
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
  let tab = selectDeliveryTab(tabs);
  if (!tab) tab = await chrome.tabs.create({ url, active: false });
  if (tab.status !== "complete") {
    for (let index = 0; index < 40; index++) { await delay(250); tab = await chrome.tabs.get(tab.id); if (tab.status === "complete") break; }
  }
  if (item.provider === "gemini") await injectProviderAdapter(tab.id, item.provider);
  const request = {
    type: "syndicatum.provider.deliver",
    provider: item.provider,
    text: notificationFor(item, item.message),
    delivery: { provider: item.provider, project_id: item.project_id, agent_id: item.agent_id, message_id: item.message.id },
  };
  const metadata = { matchingTabCount: tabs.length, selectedTabWasActive: Boolean(tab.active), selectedTabWasDiscarded: Boolean(tab.discarded) };
  try { return { ...(await chrome.tabs.sendMessage(tab.id, request)), ...metadata }; }
  catch (error) {
    if (!String(error?.message || error).includes("Receiving end does not exist")) throw error;
    await injectProviderAdapter(tab.id, item.provider);
    return { ...(await chrome.tabs.sendMessage(tab.id, request)), ...metadata, adapterInjected: true };
  }
}

function deliveryMetadata(result) {
  const { responseText: _responseText, ...metadata } = result || {};
  return metadata;
}

async function markProviderAccepted(delivery) {
  const provider = String(delivery?.provider || "");
  const projectId = Number(delivery?.project_id);
  const agentId = Number(delivery?.agent_id);
  const messageId = Number(delivery?.message_id);
  if (provider !== "gemini" || !Number.isInteger(projectId) || !Number.isInteger(agentId) || !Number.isInteger(messageId)) {
    throw new Error("The provider acceptance envelope is invalid.");
  }
  return api("/api/v1/connector-notification-deliveries.php", {
    method: "POST",
    body: JSON.stringify({ provider, project_id: projectId, agent_id: agentId, message_id: messageId }),
  });
}

async function injectProviderAdapter(tabId, provider) {
  if (!PROVIDERS[provider]) throw new Error(`No injectable adapter is available for ${provider}.`);
  await chrome.scripting.executeScript({ target: { tabId }, files: [`providers/${provider}.js`, "content.js"] });
}

function connectRealtime(bindings) {
  const projects = new Set(bindings.map(binding => String(binding.project_id)));
  for (const [projectId, socket] of sockets) if (!projects.has(projectId)) { stopHeartbeat(projectId); socket.close(1000, "Binding removed"); sockets.delete(projectId); }
  for (const projectId of projects) if (!sockets.has(projectId)) void connectProject(projectId);
}

async function connectProject(projectId) {
  try {
    const admission = await api(`/api/v1/connector-realtime-admission.php?project_id=${encodeURIComponent(projectId)}`);
    if (!admission.enabled) return;
    const socket = new WebSocket(admission.websocket_url); sockets.set(projectId, socket);
    let authenticated = false; let joinRequested = false; let joined = false;
    socket.onmessage = async event => {
      let envelope; try { envelope = JSON.parse(event.data); } catch { return; }
      if (envelope?.phase === "system" && envelope.type === "session.awaiting-auth" && !authenticated) {
        socket.send(JSON.stringify({ namespace: "pbb.realtime.v1", phase: "request", type: "session.auth.request", id: `req_auth_${uuid()}`, payload: { token: admission.token }, meta: {} })); return;
      }
      if (["ack", "response"].includes(envelope?.phase) && String(envelope.type || "").startsWith("session.auth") && !joinRequested) {
        authenticated = true; joinRequested = true;
        socket.send(JSON.stringify({ namespace: "pbb.realtime.v1", phase: "request", type: "room.join.request", id: `req_join_${uuid()}`, room: admission.room, payload: {}, meta: {} })); return;
      }
      if (["ack", "response"].includes(envelope?.phase) && String(envelope.type || "").startsWith("room.join") && !joined) {
        joined = true;
        startHeartbeat(projectId, socket);
        return;
      }
      if (envelope?.phase === "event" && envelope.type === "syndicatum.message.created" && envelope.payload?.message) {
        const current = await state();
        for (const binding of (current.bindings || []).filter(entry => String(entry.project_id) === projectId)) {
          if (bindingAcceptsMessage(binding, envelope.payload.message)) await enqueue(recoveryItem(binding, envelope.payload.message));
        }
      }
    };
    socket.onclose = () => {
      if (sockets.get(projectId) !== socket) return;
      stopHeartbeat(projectId);
      sockets.delete(projectId);
      setTimeout(() => void start(), 1000);
    };
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
  for (const projectId of heartbeatTimers.keys()) stopHeartbeat(projectId);
  for (const socket of sockets.values()) socket.close(1000, "Disconnected");
  sockets.clear();
  await chrome.alarms.clearAll();
  await chrome.storage.local.remove(STATE_KEY);
}

async function publicStatus() {
  const current = await state();
  return { status: current.status || "disconnected", baseUrl: current.baseUrl || "https://chatviewer.pbb.ph", userCode: current.pending?.userCode || null, bindingCount: current.bindings?.length || 0, queuedCount: Object.keys(current.queue || {}).length, realtimeProjectCount: heartbeatTimers.size, lastSyncAt: current.lastSyncAt || null, lastDeliveryAt: current.lastDeliveryAt || null, lastDeliveryDiagnostic: current.lastDeliveryDiagnostic || null, lastBindingMessage: current.lastBindingMessage || null, lastError: current.lastError || null };
}

chrome.runtime.onMessage.addListener((request, sender, respond) => {
  const action = request?.type;
  const operation = action === "syndicatum.provider.accepted" ? markProviderAccepted(request.delivery)
    : action === "syndicatum.connect" ? beginConnection(request.baseUrl)
    : action === "syndicatum.disconnect" ? disconnect().then(publicStatus)
    : action === "syndicatum.refresh" ? start().then(publicStatus)
    : action === "syndicatum.bind-discussion" ? bindActiveDiscussion(request.bindingCode)
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
