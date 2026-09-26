import test from "node:test";
import assert from "node:assert/strict";
import { bindingAcceptsMessage, bindingInventorySignature, bindingsFromResponse, companionDiagnostics, companionHealth, deliveryKey, deliveryReviewItems, discussionIdentity, isUncertainDeliveryFailure, matchingDiscussionTabs, normalizeBaseUrl, normalizeDiscussionUrl, notificationFor, providerForDiscussionUrl, selectDeliveryTab, serverFailureKind } from "../extension/core.mjs";

const binding = { provider: "chatgpt", project_id: 3, agent_id: 30, participant_id: 44, agent_name: "Reviewer" };
const message = { id: 91, project_sequence: 17, sender: { participant_id: 8, display_name: "Jonathan" }, addressees: [{ participant_id: 44, reason: "direct" }] };

test("requires an operator-provided Syndicatum server URL", () => {
  assert.throws(() => normalizeBaseUrl(""), /Enter the Syndicatum server URL/);
  assert.throws(() => normalizeBaseUrl("http://syndicatum.example"), /use HTTPS/);
  assert.equal(normalizeBaseUrl("https://syndicatum.example/install/"), "https://syndicatum.example/install");
  assert.equal(normalizeBaseUrl("http://localhost/install/"), "http://localhost/install");
});

test("normalizes an exact ChatGPT discussion URL", () => assert.equal(normalizeDiscussionUrl("https://chatgpt.com/c/abc_123/?utm_source=x"), "https://chatgpt.com/c/abc_123"));
test("accepts a custom GPT discussion URL", () => assert.equal(normalizeDiscussionUrl("https://chatgpt.com/g/g-test/c/abc_123?model=x"), "https://chatgpt.com/g/g-test/c/abc_123"));
test("rejects non-ChatGPT discussion URLs", () => assert.throws(() => normalizeDiscussionUrl("https://example.com/c/abc"), /valid ChatGPT/));
test("normalizes an exact Gemini discussion URL", () => assert.equal(normalizeDiscussionUrl("https://gemini.google.com/app/abc_123?hl=en", "gemini"), "https://gemini.google.com/app/abc_123"));
test("rejects Gemini share and non-Gemini URLs", () => {
  assert.throws(() => normalizeDiscussionUrl("https://gemini.google.com/share/abc", "gemini"), /valid Gemini/);
  assert.throws(() => normalizeDiscussionUrl("https://example.com/app/abc", "gemini"), /valid Gemini/);
});
test("detects the provider and canonical URL for an active discussion tab", () => {
  assert.deepEqual(providerForDiscussionUrl("https://chatgpt.com/c/chat-one?model=x"), { provider: "chatgpt", discussionUrl: "https://chatgpt.com/c/chat-one" });
  assert.deepEqual(providerForDiscussionUrl("https://gemini.google.com/app/gemini-one?hl=en"), { provider: "gemini", discussionUrl: "https://gemini.google.com/app/gemini-one" });
  assert.throws(() => providerForDiscussionUrl("https://example.com/"), /Open the ChatGPT or Gemini discussion/);
});
test("identifies a ChatGPT discussion by conversation ID instead of its project path", () => {
  assert.equal(discussionIdentity("https://chatgpt.com/g/g-p-project-one/c/chat-one"), "chatgpt:chat-one");
  assert.equal(discussionIdentity("https://chatgpt.com/g/g-p-renamed-project/c/chat-one?model=x"), "chatgpt:chat-one");
});
test("matches the current ChatGPT URL when the stored project path differs", () => {
  const tabs = [
    { id: 1, url: "https://chatgpt.com/g/g-p-6aa4c9ebe89081918419989b64e2b38b/c/6aa76d03-6954-83ec-9bc7-d3a6fa6ee00f" },
    { id: 2, url: "https://chatgpt.com/c/a-different-discussion" },
  ];
  const stored = "https://chatgpt.com/g/g-p-6aa4c9ebe89081918419989b64e2b38b-brand-ambassador-app/c/6aa76d03-6954-83ec-9bc7-d3a6fa6ee00f";
  assert.deepEqual(matchingDiscussionTabs(tabs, stored, "chatgpt").map(tab => tab.id), [1]);
});
test("routes only to the addressed participant", () => { assert.equal(bindingAcceptsMessage(binding, message), true); assert.equal(bindingAcceptsMessage({ ...binding, participant_id: 45 }, message), false); });
test("does not route an agent's own message back to itself", () => assert.equal(bindingAcceptsMessage(binding, { ...message, sender: { participant_id: 44 }, addressees: [{ participant_id: 44 }] }), false));
test("creates a stable provider-scoped delivery key", () => assert.equal(deliveryKey({ ...binding, message }), "chatgpt:3:30:91"));
test("ChatGPT receives only an MCP-first metadata notification", () => {
  const text = notificationFor({ ...binding, project_name: "Test Project" }, { ...message, uuid: "message-uuid", body: "Please answer this exact project request." });
  assert.match(text, /message 91/);
  assert.match(text, /sequence 17/);
  assert.match(text, /installed Syndicatum plugin/);
  assert.match(text, /post the full response there/);
  assert.match(text, /show only a concise summary here/);
  assert.match(text, /leave it unhandled and unacknowledged/);
  assert.doesNotMatch(text, /Please answer this exact project request/);
  assert.equal(text.split("\n").length, 3);
});
test("Gemini receives the authoritative body through the bound two-way bridge", () => {
  const text = notificationFor({ ...binding, provider: "gemini", project_name: "Test Project", agent_name: "Gemini" }, { ...message, uuid: "message-uuid", body: "Please answer this exact project request." });
  assert.match(text, /Syndicatum message-uuid/);
  assert.match(text, /Please answer this exact project request/);
  assert.match(text, /entire response is relayed/);
  assert.equal(text.split("\n").length, 6);
});
test("Gemini bridge refuses metadata-only recovery items", () => assert.throws(() => notificationFor({ ...binding, provider: "gemini" }, message), /Gemini message body is unavailable/));
test("extracts bindings from the connector response envelope", () => assert.deepEqual(bindingsFromResponse({ device: { id: "device" }, bindings: [binding] }), [binding]));
test("retains compatibility with a direct bindings array", () => assert.deepEqual(bindingsFromResponse([binding]), [binding]));
test("rejects malformed binding responses instead of silently showing zero", () => assert.throws(() => bindingsFromResponse({ device: {} }), /invalid connector bindings response/));
test("binding inventory comparison ignores order but detects routing changes", () => {
  const first = { ...binding, conversation_id: "https://chatgpt.com/c/one" };
  const second = { ...binding, provider: "gemini", agent_id: 31, conversation_id: "https://gemini.google.com/app/two" };
  assert.equal(bindingInventorySignature([first, second]), bindingInventorySignature([second, first]));
  assert.notEqual(bindingInventorySignature([first]), bindingInventorySignature([{ ...first, conversation_id: "https://chatgpt.com/c/changed" }]));
});
test("prefers an active matching discussion tab", () => assert.equal(selectDeliveryTab([{ id: 1, lastAccessed: 20 }, { id: 2, active: true, lastAccessed: 10 }]).id, 2));
test("otherwise prefers a live recently accessed discussion tab", () => assert.equal(selectDeliveryTab([{ id: 1, discarded: true, lastAccessed: 30 }, { id: 2, discarded: false, lastAccessed: 20 }, { id: 3, discarded: false, lastAccessed: 10 }]).id, 2));
test("handles an empty matching tab list", () => assert.equal(selectDeliveryTab([]), null));
test("reports independent server, account, realtime, binding, and delivery health", () => {
  const health = companionHealth({
    baseUrl: "https://syndicatum.example",
    accessToken: "protected",
    serverHealth: "reachable",
    accountHealth: "authorized",
    realtimeHealth: "connected",
    lastSyncAt: "2026-09-14T00:00:00Z",
    bindings: [{ project_id: 2 }, { project_id: 2 }, { project_id: 3 }],
    queue: { pending: {} },
  }, { realtimeProjectCount: 2 });
  assert.deepEqual(health, { overall: "connected", server: "reachable", account: "authorized", realtime: "connected", bindings: "active", delivery: "pending", bindingCount: 3, projectCount: 2, queuedCount: 1, reviewCount: 0, realtimeProjectCount: 2, error: null });
});
test("classifies uncertain submissions and exposes only allowlisted review metadata", () => {
  assert.equal(isUncertainDeliveryFailure({ code: "submission_unconfirmed" }), true);
  assert.equal(isUncertainDeliveryFailure(new Error("network unavailable")), false);
  const queue = {
    "chatgpt:2:41:4451": {
      provider: "chatgpt", project_id: 2, agent_id: 41, conversation_id: "https://chatgpt.com/c/secret",
      accessToken: "must-not-appear", message: { id: 4451, body: "must-not-appear" }, attempts: 3,
      queuedAt: "2026-09-26T12:00:00Z", deliveryState: "requires_review", lastError: "submission_unconfirmed",
    },
  };
  const reviews = deliveryReviewItems(queue);
  assert.deepEqual(reviews, [{ key: "chatgpt:2:41:4451", provider: "chatgpt", projectId: "2", agentId: "41", messageId: "4451", attempts: 3, queuedAt: "2026-09-26T12:00:00Z", reviewRequestedAt: null, lastError: "submission_unconfirmed", confirmation: null }]);
  assert.doesNotMatch(JSON.stringify(reviews), /must-not-appear|chatgpt\.com/);
  const health = companionHealth({ accessToken: "protected", queue });
  assert.equal(health.delivery, "review");
  assert.equal(health.reviewCount, 1);
  assert.equal(health.overall, "attention");
});
test("does not present a server failure as healthy overall", () => {
  const health = companionHealth({ baseUrl: "https://syndicatum.example", accessToken: "protected", serverHealth: "unreachable", lastServerError: "Failed to fetch" });
  assert.equal(health.overall, "attention");
  assert.equal(health.server, "unreachable");
  assert.equal(health.error, "Failed to fetch");
});
test("reports partially connected realtime projects as needing attention", () => {
  const health = companionHealth({ baseUrl: "https://syndicatum.example", accessToken: "protected", serverHealth: "reachable", lastSyncAt: "2026-09-14T00:00:00Z", bindings: [{ project_id: 2 }, { project_id: 3 }] }, { realtimeProjectCount: 1 });
  assert.equal(health.realtime, "partial");
  assert.equal(health.overall, "attention");
});
test("does not trust stale persisted realtime coverage after a worker restart", () => {
  const health = companionHealth({ baseUrl: "https://syndicatum.example", accessToken: "protected", serverHealth: "reachable", lastSyncAt: "2026-09-14T00:00:00Z", realtimeHealth: "connected", bindings: [{ project_id: 2 }] }, { realtimeProjectCount: 0 });
  assert.equal(health.realtime, "connecting");
  assert.equal(health.realtimeProjectCount, 0);
});
test("classifies HTTP, schema, and transport failures using explicit evidence", () => {
  const httpError = new Error("Database connection failed");
  httpError.httpStatus = 500;
  assert.equal(serverFailureKind(httpError), "error");
  assert.equal(serverFailureKind(new TypeError("Invalid response shape")), "error");
  const transportError = new Error("Failed to fetch");
  transportError.transportFailure = true;
  assert.equal(serverFailureKind(transportError), "unreachable");
});
test("copies useful diagnostics without protected connection state", () => {
  const text = companionDiagnostics({
    status: "connected",
    baseUrl: "https://syndicatum.example",
    accessToken: "must-not-appear",
    health: { overall: "connected", server: "reachable", account: "authorized", realtime: "connected", bindings: "active", delivery: "healthy", realtimeProjectCount: 2, projectCount: 2, bindingCount: 5, queuedCount: 0, error: "upstream-secret-must-not-appear" },
    lastSyncAt: "2026-09-15T00:00:00Z",
  }, { version: "0.10.0", id: "extension-id" });
  assert.match(text, /Version: 0\.10\.0/);
  assert.match(text, /Realtime: connected \(2\/2\)/);
  assert.match(text, /Bindings: active \(5\)/);
  assert.doesNotMatch(text, /must-not-appear/);
  assert.match(text, /Error present: Yes/);
});
