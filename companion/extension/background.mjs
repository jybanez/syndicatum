import { bindingAcceptsMessage, bindingInventorySignature, bindingsFromResponse, deliveryKey, matchingDiscussionTabs, normalizeBaseUrl, normalizeDiscussionUrl, notificationFor, providerForDiscussionUrl, PROVIDERS, recoveryItem, selectDeliveryTab } from "./core.mjs";

const STATE_KEY = "syndicatumCompanion";
const RETRY_ALARM = "syndicatum-retry";
const SYNC_ALARM = "syndicatum-sync";
const BINDING_ALARM = "syndicatum-binding-intents";
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
  return apiAt(current.baseUrl, current.accessToken, path, options);
}

async function apiAt(baseUrl, accessToken, path, options = {}) {
  const headers = { Accept: "application/json", ...(options.body ? { "Content-Type": "application/json" } : {}), ...(options.headers || {}) };
  if (accessToken) headers.Authorization = `Bearer ${accessToken}`;
  const response = await fetch(`${baseUrl}${path}`, { ...options, headers, redirect: "error" });
  const result = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(result.message || result.code || `Syndicatum returned HTTP ${response.status}.`);
  return result.data ?? result;
}

async function validateServer(baseUrl) {
  const endpoint = `${baseUrl}/api/v1/health.php`;
  const response = await fetch(endpoint, { headers: { Accept: "application/json" }, redirect: "follow" });
  const requested = new URL(endpoint);
  const resolved = new URL(response.url);
  if (resolved.origin !== requested.origin) throw new Error("Syndicatum validation redirected to a different server.");
  const result = await response.json().catch(() => ({}));
  const data = result?.data ?? result;
  const service = data?.service;
  const valid = response.ok
    && service?.id === "syndicatum"
    && service?.protocol === "syndicatum-connector-v1"
    && service?.api_version === "v1"
    && service?.capabilities?.connector_device_authorization === true;
  if (!valid) throw new Error("This server could not be verified as a compatible Syndicatum installation.");
  return service;
}

function serverPermission(baseUrl) {
  const url = new URL(baseUrl);
  return `${url.protocol}//${url.host}/*`;
}

async function beginConnection(baseUrl) {
  baseUrl = normalizeBaseUrl(baseUrl);
  await validateServer(baseUrl);
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

async function fetchBindingSnapshot(baseUrl, accessToken) {
  const deviceIds = new Set();
  const providerBindings = await Promise.all(Object.keys(PROVIDERS).map(async provider => {
    const response = await apiAt(baseUrl, accessToken, `/api/v1/connector-bindings.php?provider=${encodeURIComponent(provider)}`);
    if (response?.device?.id) deviceIds.add(String(response.device.id));
    return bindingsFromResponse(response).map(binding => ({ ...binding, provider, conversation_id: normalizeDiscussionUrl(binding.conversation_id, provider) }));
  }));
  if (deviceIds.size !== 1) throw new Error("Syndicatum returned an inconsistent device identity.");
  return { deviceId: [...deviceIds][0], bindings: providerBindings.flat() };
}

async function refreshBindings() {
  const current = await state();
  const snapshot = await fetchBindingSnapshot(current.baseUrl, current.accessToken);
  if (current.deviceId && snapshot.deviceId !== String(current.deviceId)) throw new Error("Syndicatum returned a different device identity.");
  const normalized = snapshot.bindings;
  await save({ bindings: normalized, status: "connected", lastSyncAt: new Date().toISOString(), lastError: null });
  connectRealtime(normalized);
  return normalized;
}

function closeRealtime(reason) {
  for (const projectId of heartbeatTimers.keys()) stopHeartbeat(projectId);
  const active = [...sockets.values()];
  sockets.clear();
  for (const socket of active) socket.close(1000, reason);
}

async function migrateServer(requestedBaseUrl) {
  if (running) await running;
  const current = await state();
  if (!current.accessToken || !current.deviceId || !current.baseUrl) throw new Error("Connect this Companion before changing its server.");
  const baseUrl = normalizeBaseUrl(requestedBaseUrl);
  if (baseUrl === normalizeBaseUrl(current.baseUrl)) {
    await validateServer(baseUrl);
    await refreshBindings();
    return publicStatus();
  }

  await validateServer(baseUrl);
  const snapshot = await fetchBindingSnapshot(baseUrl, current.accessToken);
  if (snapshot.deviceId !== String(current.deviceId)) {
    throw new Error("The new server did not recognize the existing Companion device. No changes were made.");
  }
  if (bindingInventorySignature(snapshot.bindings) !== bindingInventorySignature(current.bindings || [])) {
    throw new Error("The new server returned a different discussion binding inventory. No changes were made.");
  }

  const previous = structuredClone(current);
  try {
    await chrome.storage.local.set({ [STATE_KEY]: {
      ...current,
      baseUrl,
      bindings: snapshot.bindings,
      status: "connected",
      lastSyncAt: new Date().toISOString(),
      lastError: null,
      pendingServerMigration: null,
      lastServerMigration: { from: current.baseUrl, to: baseUrl, bindingCount: snapshot.bindings.length, completedAt: new Date().toISOString() },
    } });
    closeRealtime("Syndicatum server changed");
    await start();
    const migrated = await state();
    if (migrated.lastError) throw new Error(migrated.lastError);
  } catch (error) {
    await chrome.storage.local.set({ [STATE_KEY]: previous });
    closeRealtime("Syndicatum server migration rolled back");
    setTimeout(() => void start(), 0);
    throw new Error(`Server change rolled back: ${String(error?.message || error)}`);
  }

  await chrome.permissions.remove({ origins: [serverPermission(current.baseUrl)] }).catch(() => false);
  return publicStatus();
}

async function prepareServerMigration(requestedBaseUrl) {
  const current = await state();
  if (!current.accessToken || !current.deviceId || !current.baseUrl) throw new Error("Connect this Companion before changing its server.");
  const baseUrl = normalizeBaseUrl(requestedBaseUrl);
  await save({
    pendingServerMigration: { from: normalizeBaseUrl(current.baseUrl), to: baseUrl, requestedAt: new Date().toISOString() },
    lastError: null,
  });
  return publicStatus();
}

async function cancelServerMigration() {
  await save({ pendingServerMigration: null });
  return publicStatus();
}

async function resumeServerMigration() {
  const current = await state();
  const pending = current.pendingServerMigration;
  if (!pending?.to) return publicStatus();
  const permission = serverPermission(pending.to);
  if (!await chrome.permissions.contains({ origins: [permission] })) {
    throw new Error("Permission for the proposed Syndicatum server is still required.");
  }
  try {
    return await migrateServer(pending.to);
  } catch (error) {
    const latest = await state();
    if (normalizeBaseUrl(latest.baseUrl) !== normalizeBaseUrl(pending.to)) {
      await save({ pendingServerMigration: null, lastError: String(error?.message || error) });
      await chrome.permissions.remove({ origins: [permission] }).catch(() => false);
    }
    throw error;
  }
}

async function checkBindingIntents() {
  const current = await state();
  if (!current.accessToken) return;
  const result = await api("/api/v1/connector-discussion-bindings.php");
  const intents = Array.isArray(result?.intents) ? result.intents : [];
  if (!intents.length) return;
  const tabs = await chrome.tabs.query({ active: true, lastFocusedWindow: true });
  const tab = tabs.find(candidate => {
    try { return providerForDiscussionUrl(candidate?.url).provider === "chatgpt"; }
    catch (_error) { return false; }
  });
  if (!tab?.id) return;
  try {
    await chrome.tabs.sendMessage(tab.id, { type: "syndicatum.binding-intent", intent: intents[0] });
  } catch (error) {
    if (!String(error?.message || error).includes("Receiving end does not exist")) throw error;
    await injectProviderAdapter(tab.id, "chatgpt");
    await chrome.tabs.sendMessage(tab.id, { type: "syndicatum.binding-intent", intent: intents[0] });
  }
}

async function resolveBindingIntent(request, sender) {
  const action = String(request?.action || "");
  if (!["continue", "cancel"].includes(action)) throw new Error("Invalid binding action.");
  const body = { binding_intent_id: request.intentId, action };
  if (action === "continue") {
    if (!sender?.tab?.url) throw new Error("The ChatGPT discussion URL could not be read.");
    const detected = providerForDiscussionUrl(sender.tab.url);
    if (detected.provider !== "chatgpt") throw new Error("Open the intended ChatGPT discussion before continuing.");
    body.discussion_reference = detected.discussionUrl;
  }
  const result = await api("/api/v1/connector-discussion-bindings.php", { method: "POST", body: JSON.stringify(body) });
  if (action === "continue") {
    const bindings = await refreshBindings();
    await recover(bindings);
    await save({ lastBindingMessage: `Discussion Binding: Successful — ${result.agent_name || "agent"}.`, lastError: null });
  }
  setTimeout(() => void checkBindingIntents(), 0);
  return result;
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
  const providerTabs = await chrome.tabs.query({ url: `https://${PROVIDERS[item.provider].host}/*` });
  const tabs = matchingDiscussionTabs(providerTabs, url, item.provider);
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
    await chrome.alarms.create(BINDING_ALARM, { periodInMinutes: 0.5 });
    await checkBindingIntents();
  })().catch(async error => save({ lastError: String(error?.message || error) })).finally(() => { running = null; });
  return running;
}

async function disconnect() {
  const current = await state();
  closeRealtime("Disconnected");
  await chrome.alarms.clearAll();
  await chrome.storage.local.remove(STATE_KEY);
  if (current.baseUrl) await chrome.permissions.remove({ origins: [serverPermission(current.baseUrl)] }).catch(() => false);
}

async function publicStatus() {
  const current = await state();
  return { status: current.status || "disconnected", baseUrl: current.baseUrl || null, userCode: current.pending?.userCode || null, bindingCount: current.bindings?.length || 0, queuedCount: Object.keys(current.queue || {}).length, realtimeProjectCount: heartbeatTimers.size, lastSyncAt: current.lastSyncAt || null, lastDeliveryAt: current.lastDeliveryAt || null, lastDeliveryDiagnostic: current.lastDeliveryDiagnostic || null, lastBindingMessage: current.lastBindingMessage || null, pendingServerMigration: current.pendingServerMigration || null, lastServerMigration: current.lastServerMigration || null, lastError: current.lastError || null };
}

chrome.runtime.onMessage.addListener((request, sender, respond) => {
  const action = request?.type;
  const operation = action === "syndicatum.provider.accepted" ? markProviderAccepted(request.delivery)
    : action === "syndicatum.connect" ? beginConnection(request.baseUrl)
    : action === "syndicatum.disconnect" ? disconnect().then(publicStatus)
    : action === "syndicatum.prepare-server-migration" ? prepareServerMigration(request.baseUrl)
    : action === "syndicatum.resume-server-migration" ? resumeServerMigration()
    : action === "syndicatum.cancel-server-migration" ? cancelServerMigration()
    : action === "syndicatum.refresh" ? start().then(publicStatus)
    : action === "syndicatum.binding-intent-response" ? resolveBindingIntent(request, sender)
    : action === "syndicatum.status" ? publicStatus()
    : null;
  if (!operation) return false;
  operation.then(data => respond({ ok: true, data })).catch(error => respond({ ok: false, error: String(error?.message || error) }));
  return true;
});
chrome.runtime.onInstalled.addListener(() => void start());
chrome.runtime.onStartup.addListener(() => void start());
chrome.permissions.onAdded.addListener(permissions => {
  if (!Array.isArray(permissions.origins) || !permissions.origins.length) return;
  void state().then(current => {
    if (current.pendingServerMigration?.to && permissions.origins.includes(serverPermission(current.pendingServerMigration.to))) {
      return resumeServerMigration();
    }
  }).catch(error => save({ lastError: String(error?.message || error) }));
});
chrome.alarms.onAlarm.addListener(alarm => {
  if (alarm.name === RETRY_ALARM || alarm.name === SYNC_ALARM) void start();
  if (alarm.name === BINDING_ALARM) void checkBindingIntents().catch(error => save({ lastError: String(error?.message || error) }));
});
void start().then(async () => {
  const current = await state();
  if (!current.pendingServerMigration?.to) return;
  const permission = serverPermission(current.pendingServerMigration.to);
  if (await chrome.permissions.contains({ origins: [permission] })) await resumeServerMigration();
}).catch(error => save({ lastError: String(error?.message || error) }));
