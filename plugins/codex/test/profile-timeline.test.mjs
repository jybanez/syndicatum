import assert from "node:assert/strict";
import test from "node:test";
import { ProfileTimelineClient } from "../mcp/profile-timeline.mjs";

const profile = Object.freeze({
  profile_id: "1234567890abcdef.3.29",
  syndicatum_url: "https://syndicatum.wizaya.com",
  project_id: 3,
  participant_id: 41,
  agent_id: 29,
  project_name: "BimoPerks",
  identity: "Code Planner-Reviewer",
  token_prefix: "prefix",
  token: "secret-agent-token",
});

test("profile timeline requests authenticate internally and never return credential material", async () => {
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
  assert.equal(Object.hasOwn(result.profile, "token_prefix"), false);
  assert.ok(calls.every(call => call.options.headers.Authorization === "Bearer secret-agent-token"));
  assert.match(calls.at(-1).url, /project_id=3/);
});

test("profile timeline defaults to 50 messages and permits explicit 200-message recovery", async () => {
  const calls = [];
  const fetchImpl = async (url) => {
    calls.push(String(url));
    if (String(url).includes("/projects.php")) return response([{ id: 3, participant_id: 41, name: "BimoPerks" }]);
    return response([]);
  };
  const client = new ProfileTimelineClient({}, fetchImpl, async () => profile);
  await client.messages(profile.profile_id);
  await client.messages(profile.profile_id, { limit: 200 });
  const messageCalls = calls.filter(url => url.includes("/project-messages.php"));
  assert.equal(new URL(messageCalls[0]).searchParams.get("limit"), "50");
  assert.equal(new URL(messageCalls[1]).searchParams.get("limit"), "200");
});

test("profile timeline loads the one-call agent bootstrap without exposing credentials", async () => {
  const calls = [];
  const fetchImpl = async (url, options) => {
    calls.push({ url: String(url), options });
    if (String(url).includes("/projects.php")) return response([{ id: 3, participant_id: 41, name: "BimoPerks" }]);
    return response({ project: { id: 3, context_version: 2 }, assignment: { id: 41, role_version: 4 } });
  };
  const client = new ProfileTimelineClient({}, fetchImpl, async () => profile);
  const result = await client.bootstrap(profile.profile_id);
  assert.equal(result.bootstrap.project.context_version, 2);
  assert.equal(result.bootstrap.assignment.role_version, 4);
  assert.match(calls.at(-1).url, /project-bootstrap\.php\?project_id=3/);
  assert.doesNotMatch(JSON.stringify(result), /secret-agent-token/);
  assert.equal(Object.hasOwn(result.profile, "token_prefix"), false);
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
  assert.deepEqual(JSON.parse(post.options.body), { body: "Handled", direct_participant_ids: [11], mention_participant_ids: [], broadcast: false, action_requested: false, idempotency_key: "reply-1699-v1", reply_to_message_id: 1699 });
  assert.equal(result.message.id, 1700);
  assert.doesNotMatch(JSON.stringify(result), /secret-agent-token/);
});

test("profile task workflow reads shared tasks and updates with optimistic version", async () => {
  const calls = [];
  const fetchImpl = async (url, options) => {
    calls.push({ url: String(url), options });
    if (String(url).includes("/projects.php")) return response([{ id: 3, participant_id: 41, name: "BimoPerks" }]);
    if (String(url).includes("/project-tasks.php")) return response([{ id: 7, status: "open", version: 1 }]);
    return response({ id: 7, status: "in_progress", version: 2 });
  };
  const client = new ProfileTimelineClient({}, fetchImpl, async () => profile);
  const listed = await client.tasks(profile.profile_id, { assigned_to_me: true, status: "open" });
  assert.equal(listed.tasks[0].id, 7);
  assert.match(calls.at(-1).url, /assignee=me/);
  const updated = await client.updateTask(profile.profile_id, 7, { version: 1, status: "in_progress", note: "Starting" });
  const patch = calls.at(-1);
  assert.equal(patch.options.method, "PATCH");
  assert.deepEqual(JSON.parse(patch.options.body), { version: 1, status: "in_progress", note: "Starting" });
  assert.equal(updated.task.version, 2);
  assert.doesNotMatch(JSON.stringify(updated), /secret-agent-token/);
});

test("profile task workflow creates work as the selected agent without a caller-supplied task giver", async () => {
  const calls = [];
  const fetchImpl = async (url, options) => {
    calls.push({ url: String(url), options });
    if (String(url).includes("/projects.php")) return response([{ id: 3, participant_id: 41, name: "BimoPerks" }]);
    return response({ id: 8, title: "Check release", created_by_participant_id: 41 });
  };
  const client = new ProfileTimelineClient({}, fetchImpl, async () => profile);
  const created = await client.createTask(profile.profile_id, { title: "Check release", priority: "high", assignee_participant_id: 52, due_at: "" });
  const post = calls.at(-1);
  assert.equal(post.options.method, "POST");
  assert.match(post.url, /project-tasks\.php\?project_id=3/);
  assert.deepEqual(JSON.parse(post.options.body), { title: "Check release", priority: "high", due_at: "", assignee_participant_id: 52 });
  assert.equal(created.task.created_by_participant_id, 41);
  assert.doesNotMatch(post.options.body, /supervising_participant_id|created_by_participant_id/);
});

function response(data) {
  return new Response(JSON.stringify({ data }), { status: 200, headers: { "Content-Type": "application/json" } });
}
