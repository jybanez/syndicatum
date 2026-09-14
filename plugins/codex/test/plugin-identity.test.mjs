import assert from "node:assert/strict";
import { access, readFile } from "node:fs/promises";
import test from "node:test";

const manifestUrl = new URL("../.codex-plugin/plugin.json", import.meta.url);
const mcpUrl = new URL("../.mcp.json", import.meta.url);
const marketplaceUrl = new URL("../../../.agents/plugins/marketplace.json", import.meta.url);

test("Codex plugin identity is distinct from the hosted Syndicatum app", async () => {
  const manifest = JSON.parse(await readFile(manifestUrl, "utf8"));
  const mcp = JSON.parse(await readFile(mcpUrl, "utf8"));
  const marketplace = JSON.parse(await readFile(marketplaceUrl, "utf8"));
  const entry = marketplace.plugins.find(item => item.name === "codex");

  assert.equal(manifest.name, "codex");
  assert.equal(manifest.interface.displayName, "Syndicatum for Codex");
  assert.equal(manifest.interface.websiteURL, "https://syndicatum.wizaya.com/");
  assert.equal(manifest.interface.privacyPolicyURL, "https://syndicatum.wizaya.com/privacy");
  assert.equal(manifest.interface.termsOfServiceURL, "https://syndicatum.wizaya.com/terms");
  await access(new URL(`../${manifest.interface.composerIcon.replace(/^\.\//, "")}`, import.meta.url));
  await access(new URL(`../${manifest.interface.logo.replace(/^\.\//, "")}`, import.meta.url));
  assert.ok(mcp.mcpServers.syndicatum_codex);
  assert.equal(mcp.mcpServers.connector, undefined);
  assert.equal(entry?.source?.path, "./plugins/codex");
  assert.equal(marketplace.plugins.some(item => item.name === "syndicatum"), false);
});
