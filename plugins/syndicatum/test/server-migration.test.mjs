import assert from "node:assert/strict";
import test from "node:test";
import { agentProfileId } from "../mcp/agent-profile-store.mjs";
import { migrateSyndicatumServer } from "../mcp/server-migration.mjs";

const oldUrl = "https://chatviewer.pbb.ph";
const newUrl = "https://syndicatum.wizaya.com";
const sourceProfile = {
  profile_id: agentProfileId(oldUrl, 2, 33), syndicatum_url: oldUrl,
  project_id: 2, participant_id: 35, agent_id: 33, project_name: "Test Project",
  identity: "Syndicatum Developer", token_prefix: "sat_", claimed_at: "2026-09-13T00:00:00Z", token: "agent-secret",
};

test("server migration validates credentials before changing origin-scoped profiles", async () => {
  const calls = [], removed = [];
  const result = await migrateSyndicatumServer(newUrl, {}, {
    loadConfigImpl: async () => ({ mode: "device", syndicatumUrl: oldUrl, deviceId: "device-1", token: "device-secret", codexPath: "codex" }),
    validateServerImpl: async url => { calls.push(["server", url]); },
    deviceClientFactory: config => ({ bindings: async () => { calls.push(["device", config.syndicatumUrl, config.token]); } }),
    listProfilesImpl: async () => [sourceProfile],
    loadProfileImpl: async () => sourceProfile,
    profileExistsImpl: async () => false,
    agentClientFactory: config => ({ validateBinding: async () => { calls.push(["agent", config.syndicatumUrl, config.token]); } }),
    storeProfileImpl: async input => { calls.push(["store", input.syndicatumUrl, input.token]); return { profile_id: agentProfileId(input.syndicatumUrl, input.projectId, input.agentId) }; },
    saveDeviceConfigImpl: async input => { calls.push(["config", input.syndicatumUrl, input.token]); },
    removeProfileImpl: async profileId => { removed.push(profileId); },
  });
  assert.equal(result.state, "ready");
  assert.equal(result.migratedProfiles[0].previousProfileId, sourceProfile.profile_id);
  assert.equal(result.migratedProfiles[0].profileId, agentProfileId(newUrl, 2, 33));
  assert.deepEqual(calls.map(item => item[0]), ["server", "device", "agent", "store", "config"]);
  assert.deepEqual(removed, [sourceProfile.profile_id]);
});

test("server migration makes no local changes when target authentication fails", async () => {
  let stored = false, saved = false, removed = false;
  await assert.rejects(() => migrateSyndicatumServer(newUrl, {}, {
    loadConfigImpl: async () => ({ mode: "device", syndicatumUrl: oldUrl, deviceId: "device-1", token: "device-secret" }),
    validateServerImpl: async () => {},
    deviceClientFactory: () => ({ bindings: async () => {} }),
    listProfilesImpl: async () => [sourceProfile],
    loadProfileImpl: async () => sourceProfile,
    profileExistsImpl: async () => false,
    agentClientFactory: () => ({ validateBinding: async () => { throw new Error("Authentication is required."); } }),
    storeProfileImpl: async () => { stored = true; },
    saveDeviceConfigImpl: async () => { saved = true; },
    removeProfileImpl: async () => { removed = true; },
  }), /Authentication is required/);
  assert.equal(stored, false); assert.equal(saved, false); assert.equal(removed, false);
});

test("server migration rolls back newly written profiles when config persistence fails", async () => {
  const targetProfileId = agentProfileId(newUrl, 2, 33); const removed = [];
  await assert.rejects(() => migrateSyndicatumServer(newUrl, {}, {
    loadConfigImpl: async () => ({ mode: "device", syndicatumUrl: oldUrl, deviceId: "device-1", token: "device-secret" }),
    validateServerImpl: async () => {}, deviceClientFactory: () => ({ bindings: async () => {} }),
    listProfilesImpl: async () => [sourceProfile], loadProfileImpl: async () => sourceProfile,
    profileExistsImpl: async () => false, agentClientFactory: () => ({ validateBinding: async () => {} }),
    storeProfileImpl: async () => ({ profile_id: targetProfileId }),
    saveDeviceConfigImpl: async () => { throw new Error("disk full"); },
    removeProfileImpl: async profileId => { removed.push(profileId); },
  }), /disk full/);
  assert.deepEqual(removed, [targetProfileId]);
});
