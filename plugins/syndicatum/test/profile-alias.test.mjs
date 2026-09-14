import assert from "node:assert/strict";
import { mkdtemp, rm } from "node:fs/promises";
import os from "node:os";
import path from "node:path";
import test from "node:test";
import { agentProfileId, loadAgentProfile, storeAgentProfile, storeAgentProfileAlias } from "../mcp/agent-profile-store.mjs";

test("a migrated profile alias preserves an in-flight notification identity", async () => {
  const root = await mkdtemp(path.join(os.tmpdir(), "syndicatum-alias-"));
  const env = { SYNDICATUM_PLUGIN_DATA: root };
  const tokens = new Map();
  const storeTokenImpl = async (file, token) => tokens.set(file, token);
  const loadTokenImpl = async file => tokens.get(file);
  try {
    const current = await storeAgentProfile({
      syndicatumUrl: "https://syndicatum.wizaya.com", projectId: 3, participantId: 32, agentId: 29,
      projectName: "BimoPerks", identity: "Code Planner-Reviewer", token: "secret",
    }, env, { storeTokenImpl });
    const previous = agentProfileId("https://chatviewer.pbb.ph", 3, 29);
    await storeAgentProfileAlias(previous, current.profile_id, env);
    const loaded = await loadAgentProfile(previous, env, { loadTokenImpl });
    assert.equal(loaded.profile_id, current.profile_id);
    assert.equal(loaded.project_id, 3);
    assert.equal(loaded.agent_id, 29);
    assert.equal(loaded.token, "secret");
  } finally {
    await rm(root, { recursive: true, force: true });
  }
});

test("a profile alias cannot change the project or agent identity", async () => {
  const root = await mkdtemp(path.join(os.tmpdir(), "syndicatum-alias-boundary-"));
  const env = { SYNDICATUM_PLUGIN_DATA: root };
  try {
    const current = await storeAgentProfile({
      syndicatumUrl: "https://syndicatum.wizaya.com", projectId: 3, participantId: 32, agentId: 29,
      projectName: "BimoPerks", identity: "Code Planner-Reviewer", token: "secret",
    }, env, { storeTokenImpl: async () => {} });
    const differentAgent = agentProfileId("https://chatviewer.pbb.ph", 3, 30);
    await assert.rejects(() => storeAgentProfileAlias(differentAgent, current.profile_id, env), /preserve the project and agent identity/);
  } finally {
    await rm(root, { recursive: true, force: true });
  }
});
