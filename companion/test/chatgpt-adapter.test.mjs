import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";
import vm from "node:vm";

const source = await readFile(new URL("../extension/providers/chatgpt.js", import.meta.url), "utf8");

function adapterFor(turns) {
  const context = {
    document: {
      querySelector: () => null,
      querySelectorAll: () => turns,
    },
    location: { pathname: "/c/test-discussion" },
    setTimeout,
  };
  vm.runInNewContext(source, context);
  return context.SyndicatumProviderAdapters.chatgpt;
}

const visibleTurn = text => ({
  isConnected: true,
  innerText: text,
  closest: () => null,
  checkVisibility: () => true,
});

test("ChatGPT delivery deduplicates a rendered notification by its unique route identity", async () => {
  const expected = [
    "You have a message from Syndicatum Developer in Syndicatum.",
    "Route: project 2; agent 41; protected profile example; message 7102; sequence 3308.",
    "Use the installed Syndicatum plugin to load and handle the authoritative message.",
  ].join("\n");
  const rendered = "Syndicatum notification — Route project 2 / agent 41 / message 7102 / sequence 3308";

  const adapter = adapterFor([visibleTurn(rendered)]);
  const result = await adapter.deliver(expected);

  assert.equal(result.ok, true, JSON.stringify(result));
  assert.equal(result.deduplicated, true);
  assert.equal(result.confirmation, "existing_notification_turn");
});

test("ChatGPT delivery does not accept a hidden matching notification", async () => {
  const expected = "Route: project 2; agent 41; message 7102; sequence 3308.";
  const hidden = { ...visibleTurn(expected), closest: () => ({}) };

  const result = await adapterFor([hidden]).deliver(expected);

  assert.equal(result.ok, false);
  assert.equal(result.code, "composer_not_found");
});
