import test from "node:test";
import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";

const extensionUrl = new URL("../extension/", import.meta.url);

test("package permits on-demand adapter injection for pre-existing tabs", async () => {
  const manifest = JSON.parse(await readFile(new URL("manifest.json", extensionUrl), "utf8"));
  assert.equal(manifest.version, "0.1.3");
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
