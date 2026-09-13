import test from "node:test";
import assert from "node:assert/strict";
import { bindingAcceptsMessage, bindingsFromResponse, deliveryKey, normalizeDiscussionUrl, notificationFor, selectDeliveryTab } from "../extension/core.mjs";

const binding = { provider: "chatgpt", project_id: 3, agent_id: 30, participant_id: 44, agent_name: "Reviewer" };
const message = { id: 91, project_sequence: 17, sender: { participant_id: 8, display_name: "Jonathan" }, addressees: [{ participant_id: 44, reason: "direct" }] };

test("normalizes an exact ChatGPT discussion URL", () => assert.equal(normalizeDiscussionUrl("https://chatgpt.com/c/abc_123/?utm_source=x"), "https://chatgpt.com/c/abc_123"));
test("accepts a custom GPT discussion URL", () => assert.equal(normalizeDiscussionUrl("https://chatgpt.com/g/g-test/c/abc_123?model=x"), "https://chatgpt.com/g/g-test/c/abc_123"));
test("rejects non-ChatGPT discussion URLs", () => assert.throws(() => normalizeDiscussionUrl("https://example.com/c/abc"), /valid ChatGPT/));
test("routes only to the addressed participant", () => { assert.equal(bindingAcceptsMessage(binding, message), true); assert.equal(bindingAcceptsMessage({ ...binding, participant_id: 45 }, message), false); });
test("does not route an agent's own message back to itself", () => assert.equal(bindingAcceptsMessage(binding, { ...message, sender: { participant_id: 44 }, addressees: [{ participant_id: 44 }] }), false));
test("creates a stable provider-scoped delivery key", () => assert.equal(deliveryKey({ ...binding, message }), "chatgpt:3:30:91"));
test("notification contains metadata but not a message body", () => { const text = notificationFor(binding, message); assert.match(text, /message ID: 91/); assert.match(text, /Project sequence: 17/); assert.doesNotMatch(text, /secret message body/); });
test("extracts bindings from the connector response envelope", () => assert.deepEqual(bindingsFromResponse({ device: { id: "device" }, bindings: [binding] }), [binding]));
test("retains compatibility with a direct bindings array", () => assert.deepEqual(bindingsFromResponse([binding]), [binding]));
test("rejects malformed binding responses instead of silently showing zero", () => assert.throws(() => bindingsFromResponse({ device: {} }), /invalid connector bindings response/));
test("prefers an active matching discussion tab", () => assert.equal(selectDeliveryTab([{ id: 1, lastAccessed: 20 }, { id: 2, active: true, lastAccessed: 10 }]).id, 2));
test("otherwise prefers a live recently accessed discussion tab", () => assert.equal(selectDeliveryTab([{ id: 1, discarded: true, lastAccessed: 30 }, { id: 2, discarded: false, lastAccessed: 20 }, { id: 3, discarded: false, lastAccessed: 10 }]).id, 2));
test("handles an empty matching tab list", () => assert.equal(selectDeliveryTab([]), null));
