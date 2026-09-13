import test from "node:test";
import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";

const extensionUrl = new URL("../extension/", import.meta.url);

test("package permits on-demand adapter injection for pre-existing tabs", async () => {
  const manifest = JSON.parse(await readFile(new URL("manifest.json", extensionUrl), "utf8"));
  assert.equal(manifest.version, "0.1.4");
  assert.ok(manifest.permissions.includes("scripting"));
  assert.ok(manifest.host_permissions.includes("https://chatgpt.com/*"));
});

test("content listener guards against duplicate programmatic injection", async () => {
  const source = await readFile(new URL("content.js", extensionUrl), "utf8");
  assert.match(source, /__syndicatumCompanionListenerInstalled/);
});

test("background injects only packaged ChatGPT adapter files", async () => {
  const source = await readFile(new URL("background.mjs", extensionUrl), "utf8");
  assert.match(source, /files: \["providers\/chatgpt\.js", "content\.js"\]/);
  assert.doesNotMatch(source, /executeScript\([^)]*func:/s);
});

test("background keeps realtime alive and stores only metadata in delivery diagnostics", async () => {
  const source = await readFile(new URL("background.mjs", extensionUrl), "utf8");
  assert.match(source, /session\.health\.request/);
  assert.match(source, /setInterval\(sendHealth, 20000\)/);
  assert.match(source, /deliveryHistory/);
  assert.doesNotMatch(source, /deliveryHistory[^;]*message\.body/s);
});

test("ChatGPT adapter confirms the exact injected turn", async () => {
  const source = await readFile(new URL("providers/chatgpt.js", extensionUrl), "utf8");
  assert.match(source, /matchingUserTurnCount/);
  assert.match(source, /new_exact_user_turn/);
  assert.doesNotMatch(source, /userTurnCount\(\) > before/);
});
