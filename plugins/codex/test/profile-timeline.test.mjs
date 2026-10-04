import assert from "node:assert/strict";
import { mkdtemp, readFile, rm, writeFile } from "node:fs/promises";
import os from "node:os";
import path from "node:path";
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

  await client.post(profile.profile_id, { body: "Please approve", direct_participant_ids: [11],
    action_requested: true, action_request_type: "approval", idempotency_key: "approval-1" });
  assert.deepEqual(JSON.parse(calls.at(-1).options.body), {
    body: "Please approve", direct_participant_ids: [11], mention_participant_ids: [],
    broadcast: false, action_requested: true, idempotency_key: "approval-1",
    action_request_type: "approval",
  });
  await assert.rejects(() => client.post(profile.profile_id, {
    body: "Invalid typed FYI", action_request_type: "review",
  }), /requires action_requested/);
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

test("profile plan stewardship reads the plan and sends narrow link and status updates", async () => {
  const calls = [];
  const fetchImpl = async (url, options = {}) => {
    calls.push({ url: String(url), options });
    if (String(url).includes("/projects.php")) return response([{ id: 3, participant_id: 41, name: "BimoPerks" }]);
    if (String(url).includes("/project-plan.php")) return response({ milestones: [{ id: 4, status: "planned", version: 2 }], can_update_progress: true });
    return response({ id: 4, status: "in_progress", version: 3 });
  };
  const client = new ProfileTimelineClient({}, fetchImpl, async () => profile);
  const plan = await client.projectPlan(profile.profile_id);
  assert.equal(plan.plan.can_update_progress, true);
  const linked = await client.updateTaskDeliverable(profile.profile_id, 7, { version: 3, deliverable_id: 12, note: "Task 7 produced deliverable 12." });
  const linkPatch = calls.at(-1);
  assert.match(linkPatch.url, /project-task-deliverable\.php\?project_id=3&id=7/);
  assert.deepEqual(JSON.parse(linkPatch.options.body), { version: 3, deliverable_id: 12, note: "Task 7 produced deliverable 12." });
  assert.equal(linked.task.version, 3);
  const updated = await client.updateMilestoneProgress(profile.profile_id, 4, { version: 2, status: "in_progress", note: "Tasks 51–54 are underway." });
  const patch = calls.at(-1);
  assert.match(patch.url, /project-milestone-progress\.php\?project_id=3&id=4/);
  assert.deepEqual(JSON.parse(patch.options.body), { version: 2, status: "in_progress", note: "Tasks 51–54 are underway." });
  assert.equal(updated.milestone.version, 3);
});

test("profile file workflow lists canonical URLs and sends audited metadata mutations", async () => {
  const calls = [];
  const fetchImpl = async (url, options = {}) => {
    calls.push({ url: String(url), options });
    if (String(url).includes("/projects.php")) return response([{ id: 3, participant_id: 41, name: "BimoPerks" }]);
    if ((options.method || "GET") === "GET") return response({ files: [{ id: "11223344-5566-4777-8899-aabbccddeeff", name: "proof.pdf", url: "files/11223344-5566-4777-8899-aabbccddeeff", version: 2 }] });
    return response({ file: { id: "11223344-5566-4777-8899-aabbccddeeff", name: "renamed.pdf", url: "files/11223344-5566-4777-8899-aabbccddeeff", version: 3 } });
  };
  const client = new ProfileTimelineClient({}, fetchImpl, async () => profile);
  const listed = await client.projectFiles(profile.profile_id);
  assert.equal(listed.files.files[0].url, "https://syndicatum.wizaya.com/files/11223344-5566-4777-8899-aabbccddeeff");
  assert.doesNotMatch(JSON.stringify(listed), /storage_key|source_path|secret-agent-token/);
  await client.renameProjectFile(profile.profile_id, {
    file_id: "11223344-5566-4777-8899-aabbccddeeff", version: 2, name: "renamed.pdf",
    idempotency_key: "rename-project-file-0001",
  });
  const mutation = calls.at(-1);
  assert.equal(mutation.options.headers["Idempotency-Key"], "rename-project-file-0001");
  assert.deepEqual(JSON.parse(mutation.options.body), {
    operation: "rename_file", file_id: "11223344-5566-4777-8899-aabbccddeeff", version: "2", name: "renamed.pdf",
  });
});

test("profile file upload chunks locally without transmitting the source path", async () => {
  const root = await mkdtemp(path.join(os.tmpdir(), "syndicatum-file-tool-"));
  const sourcePath = path.join(root, "artifact.bin");
  await writeFile(sourcePath, Buffer.alloc((1024 * 1024) + 17, 7));
  const calls = [];
  try {
    const fetchImpl = async (url, options = {}) => {
      calls.push({ url: String(url), options });
      if (String(url).includes("/projects.php")) return response([{ id: 3, participant_id: 41, name: "BimoPerks" }]);
      return response({ file: { id: "11223344-5566-4777-8899-aabbccddeeff", url: "files/11223344-5566-4777-8899-aabbccddeeff" } });
    };
    const client = new ProfileTimelineClient({}, fetchImpl, async () => profile);
    const uploaded = await client.uploadProjectFile(profile.profile_id, {
      source_path: sourcePath, folder_id: "root", idempotency_key: "upload-project-file-0001",
    });
    const chunks = calls.filter(call => call.options.body instanceof FormData);
    assert.equal(chunks.length, 2);
    assert.equal(chunks[0].options.body.get("chunk_index"), "0");
    assert.equal(chunks[1].options.body.get("chunk_index"), "1");
    assert.equal(chunks[0].options.body.get("upload_id"), chunks[1].options.body.get("upload_id"));
    assert.equal(chunks[0].options.body.get("original_name"), "artifact.bin");
    assert.equal(chunks[0].options.headers["Content-Type"], undefined);
    assert.doesNotMatch(JSON.stringify(uploaded), /artifact\.bin.*syndicatum-file-tool|secret-agent-token/);
    assert.equal(uploaded.upload.file.url, "https://syndicatum.wizaya.com/files/11223344-5566-4777-8899-aabbccddeeff");
  } finally { await rm(root, { recursive: true, force: true }); }
});

test("profile file upload detects same-name replacement before sending bytes", async () => {
  const root = await mkdtemp(path.join(os.tmpdir(), "syndicatum-file-conflict-"));
  const sourcePath = path.join(root, "evidence.pdf");
  await writeFile(sourcePath, "%PDF-1.4\nevidence\n");
  const calls = [];
  try {
    const fetchImpl = async (url, options = {}) => {
      calls.push({ url: String(url), options });
      if (String(url).includes("/projects.php")) return response([{ id: 3, participant_id: 41, name: "BimoPerks" }]);
      return response({ files: [{ id: "11223344-5566-4777-8899-aabbccddeeff", name: "Evidence.pdf", version: 4 }] });
    };
    const client = new ProfileTimelineClient({}, fetchImpl, async () => profile);
    await assert.rejects(() => client.uploadProjectFile(profile.profile_id, {
      source_path: sourcePath, idempotency_key: "upload-project-conflict-01",
    }), /explicit replacement decision/);
    assert.equal(calls.some(call => call.options.body instanceof FormData), false);
  } finally { await rm(root, { recursive: true, force: true }); }
});

test("profile file download uses the canonical public URL and does not return the local destination", async () => {
  const root = await mkdtemp(path.join(os.tmpdir(), "syndicatum-file-download-"));
  const destination = path.join(root, "proof.txt");
  const calls = [];
  try {
    const fetchImpl = async (url, options = {}) => {
      calls.push({ url: String(url), options });
      if (String(url).includes("/projects.php")) return response([{ id: 3, participant_id: 41, name: "BimoPerks" }]);
      return new Response("project evidence", { status: 200, headers: { "Content-Type": "text/plain" } });
    };
    const client = new ProfileTimelineClient({}, fetchImpl, async () => profile);
    const downloaded = await client.downloadProjectFile(profile.profile_id, {
      file_id: "11223344-5566-4777-8899-aabbccddeeff", destination_path: destination,
    });
    assert.equal(await readFile(destination, "utf8"), "project evidence");
    assert.equal(calls.at(-1).url, "https://syndicatum.wizaya.com/files/11223344-5566-4777-8899-aabbccddeeff");
    assert.equal(calls.at(-1).options.headers.Authorization, undefined);
    assert.equal(downloaded.download.saved, true);
    assert.doesNotMatch(JSON.stringify(downloaded), /proof\.txt|secret-agent-token/);
  } finally { await rm(root, { recursive: true, force: true }); }
});

test("profile proposal workflow submits only reviewable non-secret project improvements", async () => {
  const calls = [];
  const fetchImpl = async (url, options) => {
    calls.push({ url: String(url), options });
    if (String(url).includes("/projects.php")) return response([{ id: 3, participant_id: 41, name: "BimoPerks" }]);
    return response({ id: 91, proposal_type: "project_details", status: "pending", version: 1 });
  };
  const client = new ProfileTimelineClient({}, fetchImpl, async () => profile);
  const proposed = await client.proposeProjectDetails(profile.profile_id, {
    description: "Clarify the project outcome.",
    rationale: "The existing brief is ambiguous.",
    token: "must-not-leave-the-client",
  });
  const post = calls.at(-1);
  assert.equal(post.options.method, "POST");
  assert.match(post.url, /project-change-proposals\.php\?project_id=3/);
  assert.deepEqual(JSON.parse(post.options.body), {
    description: "Clarify the project outcome.",
    rationale: "The existing brief is ambiguous.",
    proposal_type: "project_details",
  });
  assert.equal(proposed.proposal.status, "pending");
  assert.doesNotMatch(JSON.stringify(proposed), /secret-agent-token|must-not-leave-the-client/);
});

test("profile proposal workflow submits a bounded nested project plan for review", async () => {
  const calls = [];
  const fetchImpl = async (url, options) => {
    calls.push({ url: String(url), options });
    if (String(url).includes("/projects.php")) return response([{ id: 3, participant_id: 41, name: "BimoPerks" }]);
    return response({ id: 92, proposal_type: "project_plan", status: "pending", version: 1 });
  };
  const client = new ProfileTimelineClient({}, fetchImpl, async () => profile);
  const milestones = [{ title: "Technical baseline", target_date: "2030-10-15", deliverables: [{ title: "Crawl audit" }] }];
  const proposed = await client.proposeProjectPlan(profile.profile_id, {
    milestones, rationale: "Create outcome checkpoints.", token: "must-not-leave-the-client",
  });
  assert.deepEqual(JSON.parse(calls.at(-1).options.body), {
    milestones, rationale: "Create outcome checkpoints.", proposal_type: "project_plan",
  });
  assert.equal(proposed.proposal.proposal_type, "project_plan");
  assert.doesNotMatch(calls.at(-1).options.body, /must-not-leave-the-client/);
});

test("profile proposal workflow normalizes agent setup and profile-update identifiers", async () => {
  const calls = [];
  const fetchImpl = async (url, options) => {
    calls.push({ url: String(url), options });
    if (String(url).includes("/projects.php")) return response([{ id: 3, participant_id: 41, name: "BimoPerks" }]);
    return response({ id: calls.length, status: "pending" });
  };
  const client = new ProfileTimelineClient({}, fetchImpl, async () => profile);
  await client.proposeAgentSetup(profile.profile_id, { display_name: " Researcher ", role_title: "Research", supervising_participant_id: "52" });
  assert.deepEqual(JSON.parse(calls.at(-1).options.body), {
    role_title: "Research",
    display_name: "Researcher",
    supervising_participant_id: 52,
    proposal_type: "agent_setup",
  });
  await client.proposeAgentProfileUpdate(profile.profile_id, { target_agent_id: "29", role_summary: "Own release verification", supervising_participant_id: null });
  assert.deepEqual(JSON.parse(calls.at(-1).options.body), {
    role_summary: "Own release verification",
    target_agent_id: 29,
    supervising_participant_id: null,
    proposal_type: "agent_profile_update",
  });
});

function response(data) {
  return new Response(JSON.stringify({ data }), { status: 200, headers: { "Content-Type": "application/json" } });
}
