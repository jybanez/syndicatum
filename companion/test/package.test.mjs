import test from "node:test";
import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";

const extensionUrl = new URL("../extension/", import.meta.url);

test("package permits on-demand adapter injection for pre-existing tabs", async () => {
  const manifest = JSON.parse(await readFile(new URL("manifest.json", extensionUrl), "utf8"));
  assert.equal(manifest.version, "0.2.1");
  assert.ok(manifest.permissions.includes("scripting"));
  assert.ok(manifest.host_permissions.includes("https://chatgpt.com/*"));
  assert.ok(manifest.host_permissions.includes("https://gemini.google.com/*"));
});

test("popup offers one-time active-discussion binding", async () => {
  const html = await readFile(new URL("popup.html", extensionUrl), "utf8");
  const popup = await readFile(new URL("popup.js", extensionUrl), "utf8");
  const background = await readFile(new URL("background.mjs", extensionUrl), "utf8");
  assert.match(html, /id="binding-code"/);
  assert.match(html, /id="bind-discussion"/);
  assert.match(popup, /syndicatum\.bind-discussion/);
  assert.match(background, /connector-discussion-bindings\.php/);
  assert.match(background, /active: true, currentWindow: true/);
});

test("content listener guards against duplicate programmatic injection", async () => {
  const source = await readFile(new URL("content.js", extensionUrl), "utf8");
  assert.match(source, /__syndicatumCompanionListenerInstalled/);
});

test("background injects only packaged provider adapter files", async () => {
  const source = await readFile(new URL("background.mjs", extensionUrl), "utf8");
  assert.match(source, /files: \[`providers\/\$\{provider\}\.js`, "content\.js"\]/);
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

test("Gemini adapter confirms the exact injected turn", async () => {
  const source = await readFile(new URL("providers/gemini.js", extensionUrl), "utf8");
  assert.match(source, /registry\.gemini/);
  assert.match(source, /matchingUserTurnCount/);
  assert.match(source, /new_exact_user_turn/);
});
