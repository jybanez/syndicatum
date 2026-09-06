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
