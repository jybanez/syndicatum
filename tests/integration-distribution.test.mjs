import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const root = new URL("../", import.meta.url);
const policyUrl = new URL("release/integration-distribution-policy-v1.json", root);
const publicPluginUrl = new URL("plugins/openai-public/plugin.json", root);
const publicMcpUrl = new URL("plugins/openai-public/mcp.json", root);
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
  assert.equal(policy.compatibility.codex_plugin.initial_stable_ref, "codex-v0.2.0");
  assert.equal(policy.compatibility.codex_plugin.current_candidate_ref, `codex-v${plugin.version}`);
  assert.deepEqual(policy.compatibility.codex_plugin.release_history, [
    {
      version: "0.2.0",
      ref: "codex-v0.2.0",
      publication_status: "published",
      promotion_status: "not_promoted",
      production_supported: false,
      findings: [
        "MCP initialize reports serverInfo.version 0.1.0 instead of the packaged manifest version 0.2.0.",
        "Windows rollback and restoration acceptance is open after plugin-cache backup failed with access denied.",
      ],
    },
    {
      version: "0.2.2",
      ref: "codex-v0.2.2",
      publication_status: "published",
      promotion_status: "not_promoted",
      production_supported: false,
      findings: [
        "Windows 11 restored the exact tagged marketplace and loaded codex@syndicatum 0.2.2 with the expected tools, connector, and protected identity.",
        "Exact-byte provenance failed for 41 text files because the Git marketplace checkout converted LF to CRLF under core.autocrlf=true; initialize, fresh-task health, adversarial isolation, and same-version recovery evidence also remain incomplete.",
      ],
    },
    {
      version: "0.2.3",
      ref: "codex-v0.2.3",
      publication_status: "published",
      promotion_status: "not_promoted",
      production_supported: false,
      findings: [
        "Windows 10 and Windows 11 verified the exact tagged marketplace and installed package bytes, expected tool catalog, protected public identity continuity, project-scoped reads, and live connector ownership; Windows 10 also captured serverInfo.version 0.2.3 from the installed server in isolated temporary state.",
        "Background health was only a startup snapshot without a heartbeat or age gate; no safe cross-project negative fixture was available and same-version recovery remains unexercised.",
      ],
    },
  ]);
  assert.deepEqual(policy.compatibility.supported_operating_systems, []);
  assert.equal(policy.compatibility.support_window, null);
  assert.deepEqual(policy.compatibility.initial_target_operating_systems, ["Windows 10 22H2", "Windows 11"]);
  assert.match(policy.compatibility.planned_support_policy, /current stable/i);
  assert.match(policy.compatibility.planned_support_policy, /previous promoted stable/i);
  assert.match(policy.compatibility.planned_support_policy, /does not create a support entitlement/i);
  assert.equal(policy.compatibility.companion.tested_source_version, companion.version);
  assert.deepEqual(policy.compatibility.companion.installed_acceptance, [
    {
      version: companion.version,
      channel: "unpacked-pilot",
      environment: "Chrome on Windows 11",
      status: "passed",
      evidence: ["docs/evidence/companion-0.10.24-installed-acceptance-2026-10-09.md"],
      production_supported: false,
      production_blocker: "The accepted package was loaded unpacked; Chrome Web Store and Edge Add-ons publication and store-managed update acceptance remain open.",
    },
  ]);
  await readFile(new URL(policy.compatibility.companion.installed_acceptance[0].evidence[0], root));

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
  const [plugin, mcp] = await Promise.all([
    readJson(publicPluginUrl),
    readJson(publicMcpUrl),
  ]);
  const openai = plugin.extensions?.["com.openai"];
  const listing = openai?.interface;
  const review = openai?.review;

  assert.ok(plugin.version.match(/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/));
  assert.ok(listing, "Portable OpenAI plugin requires extensions.com.openai.interface");
  assert.ok(listing.displayName.length <= 30);
  assert.ok(listing.shortDescription.length <= 30);
  assert.ok(listing.longDescription.length <= 4000);
  assert.ok(listing.developerName.length <= 80);
  assert.ok(Array.isArray(listing.capabilities) && listing.capabilities.length <= 20);
  assert.ok(Array.isArray(listing.defaultPrompt) && listing.defaultPrompt.length <= 3);
  for (const prompt of listing.defaultPrompt) assert.ok(prompt.length <= 128);
  for (const key of ["websiteURL", "supportURL", "privacyPolicyURL", "termsOfServiceURL"]) {
    const url = new URL(listing[key]);
    assert.equal(url.protocol, "https:", `${key} must use HTTPS`);
    assert.ok(listing[key].length <= 1024);
  }
  for (const key of ["composerIcon", "logo"]) {
    assert.match(listing[key], /^\.\/assets\//, `${key} must be a package-relative asset`);
  }

  assert.equal(review.test_cases.positive.length, 5);
  assert.equal(review.test_cases.negative.length, 3);
  for (const testCase of review.test_cases.positive) {
    assert.ok(testCase.prompt);
    assert.ok(testCase.tools_triggered);
    assert.ok(testCase.expected_behavior);
  }
  assert.equal(mcp.$schema, "https://agent-plugins.org/schemas/1.0.0/mcp.schema.json");
  const servers = Object.values(mcp.mcpServers ?? {});
  assert.equal(servers.length, 1);
  assert.equal(servers[0].type, "streamable-http");
  assert.equal(new URL(servers[0].url).protocol, "https:");
});

test("mobile work remains gated until explicit owner approval", async () => {
  const policy = await readJson(policyUrl);
  assert.equal(policy.mobile_app.classification, "gated");
  assert.equal(policy.mobile_app.owner_approval_required, true);
  assert.equal(policy.mobile_app.approved, false);
  assert.equal(policy.mobile_app.implementation_planned, false);
});
