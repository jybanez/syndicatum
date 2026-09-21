import test from "node:test";
import assert from "node:assert/strict";

test("authorization polls are single-flight and HTTP 429 pauses even manual refresh", async () => {
  const originalSetTimeout = globalThis.setTimeout;
  const originalClearTimeout = globalThis.clearTimeout;
  const originalFetch = globalThis.fetch;
  const originalChrome = globalThis.chrome;
  const originalNow = Date.now;
  const handlers = {};
  const timers = new Map();
  const storage = {};
  let clock = originalNow();
  let nextTimer = 0;
  let exchanges = 0;
  let finishFirstExchange;

  globalThis.setTimeout = callback => {
    const id = ++nextTimer;
    timers.set(id, callback);
    return id;
  };
  globalThis.clearTimeout = id => timers.delete(id);
  Date.now = () => clock;
  globalThis.chrome = {
    storage: { local: {
      get: async key => ({ [key]: storage[key] }),
      set: async patch => Object.assign(storage, patch),
      remove: async key => { delete storage[key]; },
    } },
    tabs: { create: async () => ({}) },
    alarms: {
      create: async () => {},
      clearAll: async () => {},
      onAlarm: { addListener: callback => { handlers.alarm = callback; } },
    },
    permissions: {
      onAdded: { addListener: () => {} },
      contains: async () => false,
    },
    runtime: {
      onMessage: { addListener: callback => { handlers.message = callback; } },
      onInstalled: { addListener: () => {} },
      onStartup: { addListener: () => {} },
    },
  };
  const response = (data, status = 200) => ({
    ok: status < 400,
    status,
    url: "https://syndicatum.example/api/v1/health.php",
    json: async () => data,
  });
  globalThis.fetch = async url => {
    if (url.endsWith("/health.php")) return response({ data: { service: {
      id: "syndicatum", protocol: "syndicatum-connector-v1", api_version: "v1",
      capabilities: { connector_device_authorization: true },
    } } });
    if (url.endsWith("/connector-device-authorizations.php")) return response({ data: {
      device_code: "test-device-code", user_code: "TEST-CODE", verification_uri: "/connector-authorize.php",
      expires_at: new Date(clock + 600000).toISOString(),
    } }, 201);
    if (url.endsWith("/connector-device-token.php")) {
      exchanges += 1;
      if (exchanges === 1) return new Promise(resolve => { finishFirstExchange = () => resolve(response({ data: { status: "pending" } })); });
      return response({ code: "RATE_LIMITED", message: "Device authorization is invalid or expired." }, 429);
    }
    throw new Error(`Unexpected request: ${url}`);
  };
  const message = request => new Promise(resolve => handlers.message(request, {}, resolve));

  try {
    await import(new URL("../extension/background.mjs", import.meta.url));
    assert.equal((await message({ type: "syndicatum.connect", baseUrl: "https://syndicatum.example" })).ok, true);

    const firstRefresh = message({ type: "syndicatum.refresh" });
    const secondRefresh = message({ type: "syndicatum.refresh" });
    for (let i = 0; i < 20 && !finishFirstExchange; i++) await new Promise(resolve => setImmediate(resolve));
    assert.equal(exchanges, 1, "concurrent refreshes must share the token request");
    handlers.alarm({ name: "syndicatum-retry" });
    assert.equal(exchanges, 1, "the retry alarm must not overlap an in-flight token request");
    finishFirstExchange();
    await Promise.all([firstRefresh, secondRefresh]);

    clock += 15000;
    const scheduled = [...timers.values()];
    timers.clear();
    for (const callback of scheduled) callback();
    for (let i = 0; i < 20 && exchanges < 2; i++) await new Promise(resolve => setImmediate(resolve));
    assert.equal(exchanges, 2);
    for (let i = 0; i < 20 && !storage.syndicatumCompanion?.authorizationRetryAt; i++) await new Promise(resolve => setImmediate(resolve));
    assert.equal(storage.syndicatumCompanion.authorizationRetryAt, clock + 60000);
    assert.match(storage.syndicatumCompanion.lastError, /Too many authorization checks/);

    await message({ type: "syndicatum.refresh" });
    assert.equal(exchanges, 2, "manual refresh must respect the rate-limit cooldown");
  } finally {
    globalThis.setTimeout = originalSetTimeout;
    globalThis.clearTimeout = originalClearTimeout;
    globalThis.fetch = originalFetch;
    globalThis.chrome = originalChrome;
    Date.now = originalNow;
  }
});
