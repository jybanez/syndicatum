import assert from "node:assert/strict";
import test from "node:test";
import { AuthorizationListener } from "../mcp/authorization-listener.mjs";

test("authorization listener exchanges once on join and completes from the Realtime approval event", async () => {
  const originalWebSocket = globalThis.WebSocket;
  class FakeWebSocket {
    constructor() { this.listeners = new Map(); FakeWebSocket.instance = this; }
    addEventListener(name, listener) { this.listeners.set(name, listener); }
    send() {}
    close(code = 1000) { this.emit("close", { code }); }
    emit(name, value) { this.listeners.get(name)?.(value); }
    message(envelope) { this.emit("message", { data: JSON.stringify(envelope) }); }
  }
  globalThis.WebSocket = FakeWebSocket;
  let exchanges = 0; let authorized = null;
  const pending = {
    authorizationId: "abcd1234", expiresAt: new Date(Date.now() + 60000).toISOString(),
    realtime: { enabled: true, token: "temporary", room: "authorization.room", websocket_url: "wss://example.test/realtime" },
  };
  const listener = new AuthorizationListener({ pending, log: { info() {}, error() {} },
    exchange: async () => (++exchanges === 1 ? { status: "pending" } : { status: "authorized", device_id: "device-1", access_token: "secret" }),
    onAuthorized: async result => { authorized = result.device_id; },
  });
  try {
    const running = listener.connectOnce(); const socket = FakeWebSocket.instance;
    socket.message({ phase: "system", type: "session.awaiting-auth" });
    socket.message({ phase: "ack", type: "session.auth.ack" });
    socket.message({ phase: "ack", type: "room.join.ack" });
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(exchanges, 1);
    socket.message({ phase: "event", type: "connector.authorization.approved", payload: { authorization_id: "abcd1234" } });
    await running;
    assert.equal(exchanges, 2); assert.equal(authorized, "device-1"); assert.equal(listener.completed, true);
  } finally { globalThis.WebSocket = originalWebSocket; }
});
