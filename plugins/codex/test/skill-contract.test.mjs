import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const skillUrl = new URL("../skills/syndicatum-timeline/SKILL.md", import.meta.url);
const coordinationUrl = new URL("../skills/syndicatum-timeline/references/coordination.md", import.meta.url);
const proposalsUrl = new URL("../skills/syndicatum-timeline/references/project-proposals.md", import.meta.url);
const stewardshipUrl = new URL("../skills/syndicatum-timeline/references/project-plan-stewardship.md", import.meta.url);

test("timeline skill uses Project API V1 and rejects legacy feed reads", async () => {
  const source = await readFile(skillUrl, "utf8");
  const coordination = await readFile(coordinationUrl, "utf8");
  assert.match(source, /name: syndicatum-timeline/);
  assert.match(source, /syndicatum_list_projects/);
  assert.match(source, /syndicatum_get_bootstrap/);
  assert.match(coordination, /syndicatum_list_messages/);
  assert.match(coordination, /syndicatum_list_tasks/);
  assert.match(coordination, /Responsibility Inbox/);
  assert.match(coordination, /acknowledgement is not action-request\s+resolution and is not task completion/);
  assert.match(source, /Syndicatum profile ID/);
  assert.doesNotMatch(source, /pbb-chat-token\.local\.json/);
  assert.match(source, /Do not use `\/api\/chat-log\.php` or `\/api\/chat-entries\.php`/);
});

test("timeline skill keeps direct plan stewardship narrow and permissioned", async () => {
  const source = await readFile(skillUrl, "utf8");
  const stewardship = await readFile(stewardshipUrl, "utf8");
  assert.match(source, /project-plan stewardship/);
  assert.match(stewardship, /permissions\.plan\.progress\.update/);
  assert.match(stewardship, /syndicatum_get_project_plan/);
  assert.match(stewardship, /syndicatum_update_task_deliverable/);
  assert.match(stewardship, /syndicatum_update_milestone_progress/);
  assert.match(stewardship, /syndicatum_update_deliverable_progress/);
  assert.match(stewardship, /structural changes remain proposals/i);
});

test("timeline skill explains the Codex bind and claim-code flow", async () => {
  const source = await readFile(skillUrl, "utf8");
  assert.match(source, /Treat `syndicatum bind <project> <identity>` in Codex as a protected agent-claim\s+request/);
  assert.match(source, /Team actions → Add Agent/);
  assert.match(source, /Edit agent →\s*Credential actions →\s*Generate new claim code/);
  assert.match(source, /Generate replacement\s+claim code/);
  assert.match(source, /expires after 15 minutes, is single-use,\s*and is shown only once/);
  assert.match(source, /[Cc]all `claim_agent_profile`/);
  assert.match(source, /Do not repeat the code/);
  assert.match(source, /After a successful first-time claim/);
  assert.match(source, /call `syndicatum_get_bootstrap`/);
  assert.match(source, /call `syndicatum_post_message` to broadcast a short\s+first-person introduction/);
  assert.match(source, /agent-introduction:<profile_id>/);
  assert.match(source, /Do not post another\s+introduction when reusing an exact existing profile or replacing its\s+credential/);
  assert.match(source, /Never\s+call `claim_agent_profile` again.*introduction failed/s);
  assert.match(source, /Copy deeplink/);
  assert.match(source, /Claiming an identity and routing notifications are\s+separate operations/);
});

test("timeline skill routes project improvements through human-reviewed proposals", async () => {
  const source = await readFile(skillUrl, "utf8");
  const proposals = await readFile(proposalsUrl, "utf8");
  assert.match(source, /references\/project-proposals\.md/);
  assert.match(proposals, /syndicatum_propose_project_details/);
  assert.match(proposals, /syndicatum_propose_project_plan/);
  assert.match(source, /must use\s+`syndicatum_propose_project_plan`/);
  assert.match(proposals, /Do not paste the plan into `syndicatum_post_message`/);
  assert.match(proposals, /must not replace the proposal/);
  assert.match(proposals, /Do not downgrade the\s+request into a normal message/);
  assert.match(proposals, /syndicatum_propose_agent_setup/);
  assert.match(proposals, /syndicatum_propose_agent_profile_update/);
  assert.match(proposals, /never changes the project automatically/);
  assert.match(proposals, /Agents cannot approve their\s+own proposals/);
  assert.match(proposals, /must never contain API keys, tokens, secrets/);
  assert.match(proposals, /event-poster project brief/);
});
