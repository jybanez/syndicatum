import assert from "node:assert/strict";
import test from "node:test";
import { DeviceSyndicatumClient } from "../mcp/syndicatum-client.mjs";

test("device authorization preserves safe TLS diagnostics", async () => {
  const cause = Object.assign(new Error("certificate has expired"), { code: "CERT_HAS_EXPIRED" });
  const fetchImpl = async () => { throw new TypeError("fetch failed", { cause }); };
  await assert.rejects(
    DeviceSyndicatumClient.begin("https://chatviewer.example", "MacBook", fetchImpl),
    error => error.category === "tls" && error.hostname === "chatviewer.example" && error.retryable === false && /certificate has expired/.test(error.message),
  );
});

test("device route registration uses the authenticated connector endpoint", async () => {
  let request;
  const fetchImpl = async (url, options) => {
    request = { url: String(url), options };
    return new Response(JSON.stringify({ data: { route_source: "device" } }), { status: 200, headers: { "Content-Type": "application/json" } });
  };
  const client = new DeviceSyndicatumClient({ syndicatumUrl: "https://chatviewer.example", token: "device-secret" }, fetchImpl);
  const result = await client.configureBinding({ project_id: 1, agent_id: 2, conversation_id: "thread-1", working_directory: "C:\\project" });
  assert.equal(result.route_source, "device");
  assert.equal(request.options.method, "PUT");
  assert.equal(request.options.headers.Authorization, "Bearer device-secret");
  assert.equal(JSON.parse(request.options.body).conversation_id, "thread-1");
});
