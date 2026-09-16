import assert from "node:assert/strict";
import test from "node:test";
import { DeviceSyndicatumClient, SyndicatumClient, validateSyndicatumServer } from "../mcp/syndicatum-client.mjs";

test("addressed-message recovery retains its explicit 100-message batch", async () => {
  const client = new SyndicatumClient({ syndicatumUrl: "https://syndicatum.example", projectId: "2", token: "test-token" }, async url => {
    assert.equal(url.searchParams.get("limit"), "100");
    return new Response(JSON.stringify({ data: [] }), { status: 200 });
  });
  assert.deepEqual(await client.addressedUnacknowledged(), []);
});

test("device authorization preserves safe TLS diagnostics", async () => {
  const cause = Object.assign(new Error("certificate has expired"), { code: "CERT_HAS_EXPIRED" });
  const fetchImpl = async () => { throw new TypeError("fetch failed", { cause }); };
  await assert.rejects(
    DeviceSyndicatumClient.begin("https://chatviewer.example", "MacBook", fetchImpl),
    error => error.category === "tls" && error.hostname === "chatviewer.example" && error.retryable === false && /certificate has expired/.test(error.message),
  );
});

test("server validation requires the Syndicatum connector protocol identity", async () => {
  const accepted = await validateSyndicatumServer("https://syndicatum.example", async url => {
    assert.equal(url.href, "https://syndicatum.example/api/v1/health.php");
    return new Response(JSON.stringify({ data: { service: { id: "syndicatum", protocol: "syndicatum-connector-v1", api_version: "v1" } } }), { status: 200 });
  });
  assert.equal(accepted.syndicatumUrl, "https://syndicatum.example");
  await assert.rejects(() => validateSyndicatumServer("https://not-syndicatum.example", async () => new Response(JSON.stringify({ data: { service: { id: "other" } } }), { status: 200 })), /compatible Syndicatum instance/);
});
