import assert from "node:assert/strict";
import test from "node:test";
import { ProfileTimelineClient } from "../mcp/profile-timeline.mjs";

const profile = Object.freeze({
  profile_id: "1234567890abcdef.3.29",
  syndicatum_url: "https://chatviewer.pbb.ph",
  project_id: 3,
  participant_id: 41,
  agent_id: 29,
  project_name: "BimoPerks",
  identity: "Code Planner-Reviewer",
  token_prefix: "prefix",
  token: "secret-agent-token",
});

test("profile timeline requests authenticate internally and never return the token", async () => {
  const calls = [];
  const fetchImpl = async (url, options) => {
    calls.push({ url: String(url), options });
    if (String(url).includes("/projects.php")) return response([{ id: 3, participant_id: 41, name: "BimoPerks" }]);
    if (String(url).includes("/project-participants.php")) return response([{ id: 41, display_name: "Code Planner-Reviewer" }]);
    return response([]);
  };
  const client = new ProfileTimelineClient({}, fetchImpl, async profileId => {
    assert.equal(profileId, profile.profile_id);
    return profile;
  });
  const result = await client.participants(profile.profile_id);
  assert.equal(result.profile.agent_id, 29);
  assert.doesNotMatch(JSON.stringify(result), /secret-agent-token/);
  assert.ok(calls.every(call => call.options.headers.Authorization === "Bearer secret-agent-token"));
  assert.match(calls.at(-1).url, /project_id=3/);
});

test("profile timeline posts as the selected profile with stable idempotency", async () => {
  const calls = [];
  const fetchImpl = async (url, options) => {
    calls.push({ url: String(url), options });
    if (String(url).includes("/projects.php")) return response([{ id: 3, participant_id: 41, name: "BimoPerks" }]);
    return response({ id: 1700, body: "Handled" });
  };
  const client = new ProfileTimelineClient({}, fetchImpl, async () => profile);
  const result = await client.post(profile.profile_id, { body: "Handled", direct_participant_ids: [11], reply_to_message_id: 1699, idempotency_key: "reply-1699-v1" });
  const post = calls.at(-1);
  assert.equal(post.options.method, "POST");
  assert.equal(post.options.headers["Idempotency-Key"], "reply-1699-v1");
  assert.deepEqual(JSON.parse(post.options.body), { body: "Handled", direct_participant_ids: [11], mention_participant_ids: [], broadcast: false, idempotency_key: "reply-1699-v1", reply_to_message_id: 1699 });
  assert.equal(result.message.id, 1700);
  assert.doesNotMatch(JSON.stringify(result), /secret-agent-token/);
});

function response(data) {
  return new Response(JSON.stringify({ data }), { status: 200, headers: { "Content-Type": "application/json" } });
}
