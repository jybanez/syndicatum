import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const root = new URL("../", import.meta.url);
const policyUrl = new URL("release/integration-distribution-policy-v1.json", root);
const publicPluginUrl = new URL("plugins/openai-public/plugin.json", root);
const pluginUrl = new URL("plugins/codex/.codex-plugin/plugin.json", root);
const companionUrl = new URL("companion/extension/manifest.json", root);

const readJson = async url => JSON.parse(await readFile(url, "utf8"));

test("distribution classifications match the exact source package versions", async () => {
  const [policy, publicPlugin, plugin, companion] = await Promise.all([
    readJson(policyUrl),
    readJson(publicPluginUrl),
    readJson(pluginUrl),
    readJson(companionUrl),
  ]);

  assert.equal(policy.schema_version, 2);
  assert.equal(policy.release_train.openai_public_plugin, publicPlugin.version);
  assert.equal(policy.release_train.codex_plugin, plugin.version);
  assert.equal(policy.release_train.companion, companion.version);
  assert.equal(policy.compatibility.codex_plugin.tested_source_version, plugin.version);
  assert.equal(policy.compatibility.codex_plugin.initial_stable_ref, `codex-v${plugin.version}`);
  assert.deepEqual(policy.compatibility.supported_operating_systems, []);
  assert.equal(policy.compatibility.support_window, null);
  assert.deepEqual(policy.compatibility.initial_target_operating_systems, ["Windows 10 22H2", "Windows 11"]);
  assert.match(policy.compatibility.planned_support_policy, /current stable/i);
  assert.match(policy.compatibility.planned_support_policy, /previous stable/i);
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
    const gateIds = new Set();
    for (const gate of channel.required_gates) {
      assert.match(gate.id, /^[a-z0-9]+(?:-[a-z0-9]+)*$/);
      assert.ok(!gateIds.has(gate.id), `Duplicate gate ${channel.id}/${gate.id}`);
      gateIds.add(gate.id);
      assert.ok(typeof gate.requirement === "string" && gate.requirement.trim().length > 0);
      assert.ok(["open", "blocked", "passed"].includes(gate.status));
      assert.ok(Array.isArray(gate.evidence));
      for (const evidence of gate.evidence) {
        assert.ok(typeof evidence === "string" && evidence.trim().length > 0);
      }
      if (gate.status === "passed") {
        assert.ok(gate.evidence.length > 0, `Passed gate lacks evidence: ${channel.id}/${gate.id}`);
      }
    }
    if (channel.classification === "production") {
      assert.equal(channel.production_eligible, true);
      assert.ok(channel.published_identifier);
      assert.ok(channel.required_gates.every(gate => gate.status === "passed"));
    } else {
      assert.equal(channel.production_eligible, false);
    }
  }
});

test("non-production channels retain at least one explicit unresolved gate", async () => {
  const policy = await readJson(policyUrl);
  for (const channel of policy.channels) {
    if (channel.production_eligible) continue;
    assert.ok(channel.required_gates.some(gate => gate.status !== "passed"));
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
