import test from "node:test";
import assert from "node:assert/strict";
import { bindingAcceptsMessage, bindingInventorySignature, bindingsFromResponse, companionHealth, deliveryKey, discussionIdentity, matchingDiscussionTabs, normalizeBaseUrl, normalizeDiscussionUrl, notificationFor, providerForDiscussionUrl, selectDeliveryTab } from "../extension/core.mjs";

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
  assert.match(text, /message ID: 91/);
  assert.match(text, /Project sequence: 17/);
  assert.match(text, /installed Syndicatum plugin/);
  assert.match(text, /complete, detailed response in Syndicatum through MCP/);
  assert.match(text, /only a concise summary/);
  assert.match(text, /leave the project message unhandled and unacknowledged/);
  assert.doesNotMatch(text, /Please answer this exact project request/);
});
test("Gemini receives the authoritative body through the bound two-way bridge", () => {
  const text = notificationFor({ ...binding, provider: "gemini", project_name: "Test Project", agent_name: "Gemini" }, { ...message, uuid: "message-uuid", body: "Please answer this exact project request." });
  assert.match(text, /Syndicatum bridge request message-uuid/);
  assert.match(text, /Please answer this exact project request/);
  assert.match(text, /entire assistant response will be relayed/);
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
  assert.deepEqual(health, { overall: "connected", server: "reachable", account: "authorized", realtime: "connected", bindings: "active", delivery: "pending", bindingCount: 3, projectCount: 2, queuedCount: 1, realtimeProjectCount: 2, error: null });
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
