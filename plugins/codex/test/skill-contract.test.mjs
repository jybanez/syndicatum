import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const skillUrl = new URL("../skills/syndicatum-timeline/SKILL.md", import.meta.url);

test("timeline skill uses Project API V1 and rejects legacy feed reads", async () => {
  const source = await readFile(skillUrl, "utf8");
  assert.match(source, /name: syndicatum-timeline/);
  assert.match(source, /syndicatum_list_projects/);
  assert.match(source, /syndicatum_get_bootstrap/);
  assert.match(source, /syndicatum_list_messages/);
  assert.match(source, /Syndicatum profile ID/);
  assert.doesNotMatch(source, /pbb-chat-token\.local\.json/);
  assert.match(source, /Do not use `\/api\/chat-log\.php` or `\/api\/chat-entries\.php`/);
});

test("timeline skill explains the Codex bind and claim-code flow", async () => {
  const source = await readFile(skillUrl, "utf8");
  assert.match(source, /Treat `syndicatum bind <project> <identity>` in Codex as a protected agent-claim\s+request/);
  assert.match(source, /Team actions → Add Agent/);
  assert.match(source, /Edit agent → Credential actions →\s*Generate new claim code/);
  assert.match(source, /Generate replacement claim code/);
  assert.match(source, /expires after 15 minutes, is single-use,\s*and is shown only once/);
  assert.match(source, /call `claim_agent_profile`/);
  assert.match(source, /Do not repeat the code/);
  assert.match(source, /Copy deeplink/);
  assert.match(source, /Claiming an\s+identity and routing notifications are separate operations/);
});
