import assert from "node:assert/strict";
import { mkdir, mkdtemp, readFile, rm, writeFile } from "node:fs/promises";
import os from "node:os";
import path from "node:path";
import test from "node:test";
import { claimAgentProfile } from "../mcp/profile-claim.mjs";
import { agentProfileId, listAgentProfiles, loadAgentProfile, migrateLegacyProjectCredential } from "../mcp/agent-profile-store.mjs";

const protectedStore = async (file, token) => {
  await mkdir(path.dirname(file), { recursive: true });
  await writeFile(file, `protected:${Buffer.from(token).toString("base64")}\n`);
};
const protectedLoad = async file => Buffer.from((await readFile(file, "utf8")).trim().slice(10), "base64").toString("utf8");

function claimResponse(agentId, participantId, identity) {
  return new Response(JSON.stringify({ data: { project_id: 3, participant_id: participantId, agent_id: agentId, project_name: "BimoPerks", display_name: identity, token: `secret-token-${agentId}`, token_prefix: `token-${agentId}` } }), { status: 201, headers: { "Content-Type": "application/json" } });
}

test("claim action stores a protected per-agent profile outside the project and returns no secrets", async () => {
  const root = await mkdtemp(path.join(os.tmpdir(), "syndicatum-project-"));
  const data = await mkdtemp(path.join(os.tmpdir(), "syndicatum-profiles-"));
  const env = { ...process.env, SYNDICATUM_PLUGIN_DATA: data, SYNDICATUM_AGENT_TOKEN: "" };
  let request;
  const fetchImpl = async (url, options) => { request = { url: String(url), options }; return claimResponse(29, 41, "Code Planner-Reviewer"); };
  const result = await claimAgentProfile({ syndicatumUrl: "https://syndicatum.wizaya.com", project: "BimoPerks", identity: "Code Planner-Reviewer", claimCode: "one-time-code", projectRoot: root }, { fetchImpl, env, storeTokenImpl: protectedStore });
  assert.deepEqual(JSON.parse(request.options.body), { project: "BimoPerks", identity: "Code Planner-Reviewer", claim_code: "one-time-code" });
  assert.equal(request.url, "https://syndicatum.wizaya.com/api/v1/agent-claim.php");
  assert.equal(result.profileId, agentProfileId("https://syndicatum.wizaya.com", 3, 29));
  assert.equal(result.credentialStore, "user_profile");
  assert.doesNotMatch(JSON.stringify(result), /secret-token|one-time-code/);
  await assert.rejects(readFile(path.join(root, "pbb-chat-token.local.json")), error => error.code === "ENOENT");
  const profiles = await listAgentProfiles(env);
  assert.equal(profiles.length, 1);
  assert.equal(profiles[0].identity, "Code Planner-Reviewer");
  assert.doesNotMatch(JSON.stringify(profiles[0]), /secret-token/);
  const loaded = await loadAgentProfile(result.profileId, env, { loadTokenImpl: protectedLoad });
  assert.equal(loaded.token, "secret-token-29");
});

test("two agents sharing one project root receive distinct protected credential files", async () => {
  const root = await mkdtemp(path.join(os.tmpdir(), "syndicatum-shared-project-"));
  const data = await mkdtemp(path.join(os.tmpdir(), "syndicatum-shared-profiles-"));
  const env = { ...process.env, SYNDICATUM_PLUGIN_DATA: data, SYNDICATUM_AGENT_TOKEN: "" };
  let next = { agent: 29, participant: 41, identity: "Code Planner-Reviewer" };
  const fetchImpl = async () => claimResponse(next.agent, next.participant, next.identity);
  const reviewer = await claimAgentProfile({ syndicatumUrl: "https://syndicatum.wizaya.com", project: "BimoPerks", identity: next.identity, claimCode: "reviewer-code", projectRoot: root }, { fetchImpl, env, storeTokenImpl: protectedStore });
  next = { agent: 30, participant: 42, identity: "Codex Developer" };
  const developer = await claimAgentProfile({ syndicatumUrl: "https://syndicatum.wizaya.com", project: "BimoPerks", identity: next.identity, claimCode: "developer-code", projectRoot: root }, { fetchImpl, env, storeTokenImpl: protectedStore });
  assert.notEqual(reviewer.profileId, developer.profileId);
  assert.equal((await listAgentProfiles(env)).length, 2);
  assert.equal((await loadAgentProfile(reviewer.profileId, env, { loadTokenImpl: protectedLoad })).token, "secret-token-29");
  assert.equal((await loadAgentProfile(developer.profileId, env, { loadTokenImpl: protectedLoad })).token, "secret-token-30");
});

test("a device-token environment override cannot replace a claimed agent credential", async () => {
  const data = await mkdtemp(path.join(os.tmpdir(), "syndicatum-profile-boundary-"));
  const root = await mkdtemp(path.join(os.tmpdir(), "syndicatum-profile-project-"));
  const env = { ...process.env, SYNDICATUM_PLUGIN_DATA: data, SYNDICATUM_AGENT_TOKEN: "device-token" };
  let receivedEnv;
  try {
    const claimed = await claimAgentProfile({ syndicatumUrl: "https://syndicatum.wizaya.com", project: "BimoPerks", identity: "Helper", claimCode: "claim-one", projectRoot: root }, {
      env, storeTokenImpl: protectedStore, fetchImpl: async () => claimResponse(31, 34, "Helper"),
    });
    const loaded = await loadAgentProfile(claimed.profileId, env, { loadTokenImpl: async (file, tokenEnv) => {
      receivedEnv = tokenEnv;
      return protectedLoad(file);
    } });
    assert.equal(receivedEnv.SYNDICATUM_AGENT_TOKEN, "");
    assert.equal(loaded.token, "secret-token-31");
  } finally {
    await rm(data, { recursive: true, force: true });
    await rm(root, { recursive: true, force: true });
  }
});

test("an existing profile blocks claim consumption unless replacement is explicit", async () => {
  const root = await mkdtemp(path.join(os.tmpdir(), "syndicatum-existing-project-"));
  const data = await mkdtemp(path.join(os.tmpdir(), "syndicatum-existing-profiles-"));
  const env = { ...process.env, SYNDICATUM_PLUGIN_DATA: data, SYNDICATUM_AGENT_TOKEN: "" };
  const input = { syndicatumUrl: "https://syndicatum.wizaya.com", project: "BimoPerks", identity: "Code Planner-Reviewer", claimCode: "code", projectRoot: root };
  await claimAgentProfile(input, { fetchImpl: async () => claimResponse(29, 41, input.identity), env, storeTokenImpl: protectedStore });
  let called = false;
  await assert.rejects(() => claimAgentProfile(input, { fetchImpl: async () => { called = true; }, env, storeTokenImpl: protectedStore }), /already exists/);
  assert.equal(called, false);
});

test("a complete legacy project credential migrates to the protected profile store and is removed", async () => {
  const root = await mkdtemp(path.join(os.tmpdir(), "syndicatum-legacy-project-"));
  const data = await mkdtemp(path.join(os.tmpdir(), "syndicatum-legacy-profiles-"));
  const env = { ...process.env, SYNDICATUM_PLUGIN_DATA: data, SYNDICATUM_AGENT_TOKEN: "" };
  const legacyFile = path.join(root, "pbb-chat-token.local.json");
  await writeFile(legacyFile, JSON.stringify({ project_id: 3, participant_id: 41, agent_id: 29, project_name: "BimoPerks", identity: "Code Planner-Reviewer", token: "legacy-secret", token_prefix: "legacy", claimed_at: "2026-09-13T00:00:00Z", chatviewer_url: "https://syndicatum.wizaya.com" }));
  const migrated = await migrateLegacyProjectCredential(root, env, { storeTokenImpl: protectedStore });
  assert.equal(migrated.profile_id, agentProfileId("https://syndicatum.wizaya.com", 3, 29));
  await assert.rejects(readFile(legacyFile), error => error.code === "ENOENT");
  assert.equal((await loadAgentProfile(migrated.profile_id, env, { loadTokenImpl: protectedLoad })).token, "legacy-secret");
});
