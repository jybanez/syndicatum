import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const root = new URL("../", import.meta.url);
const policyUrl = new URL("release/integration-distribution-policy-v1.json", root);
const pluginUrl = new URL("plugins/codex/.codex-plugin/plugin.json", root);
const companionUrl = new URL("companion/extension/manifest.json", root);

const readJson = async url => JSON.parse(await readFile(url, "utf8"));

test("distribution classifications match the exact source package versions", async () => {
  const [policy, plugin, companion] = await Promise.all([
    readJson(policyUrl),
    readJson(pluginUrl),
    readJson(companionUrl),
  ]);

  assert.equal(policy.schema_version, 1);
  assert.equal(policy.release_train.codex_plugin, plugin.version);
  assert.equal(policy.release_train.companion, companion.version);
  assert.equal(policy.compatibility.codex_plugin.tested_source_version, plugin.version);
  assert.equal(policy.compatibility.companion.tested_source_version, companion.version);

  const ids = new Set(policy.channels.map(channel => channel.id));
  for (const id of [
    "openai-public-plugin",
    "codex-repository-marketplace",
    "companion-chrome-web-store",
    "companion-edge-add-ons",
  ]) assert.ok(ids.has(id), `Missing distribution channel ${id}`);

  for (const channel of policy.channels) {
    assert.ok(["development", "pilot", "production"].includes(channel.classification));
    assert.ok(channel.required_gates.length > 0);
    if (channel.classification === "production") {
      assert.equal(channel.production_eligible, true);
      assert.ok(channel.published_identifier);
    } else {
      assert.equal(channel.production_eligible, false);
    }
  }
});

test("public plugin listing metadata stays within submission limits", async () => {
  const plugin = await readJson(pluginUrl);
  const listing = plugin.interface;

  assert.ok(plugin.version.match(/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/));
  assert.ok(listing.displayName.length <= 30);
  assert.ok(listing.shortDescription.length <= 30);
  assert.ok(listing.longDescription.length <= 4000);
  assert.ok(listing.developerName.length <= 80);
  assert.ok(Array.isArray(listing.capabilities) && listing.capabilities.length <= 20);
  for (const key of ["websiteURL", "supportURL", "privacyPolicyURL", "termsOfServiceURL"]) {
    const url = new URL(listing[key]);
    assert.equal(url.protocol, "https:", `${key} must use HTTPS`);
    assert.ok(listing[key].length <= 1024);
  }
});

test("mobile work remains gated until explicit owner approval", async () => {
  const policy = await readJson(policyUrl);
  assert.equal(policy.mobile_app.classification, "gated");
  assert.equal(policy.mobile_app.owner_approval_required, true);
  assert.equal(policy.mobile_app.approved, false);
  assert.equal(policy.mobile_app.implementation_planned, false);
});
