import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const skillUrl = new URL("../skills/syndicatum-timeline/SKILL.md", import.meta.url);

test("timeline skill uses Project API V1 and rejects legacy feed reads", async () => {
  const source = await readFile(skillUrl, "utf8");
  assert.match(source, /name: syndicatum-timeline/);
  assert.match(source, /syndicatum_list_projects/);
  assert.match(source, /syndicatum_list_messages/);
  assert.match(source, /Syndicatum profile ID/);
  assert.doesNotMatch(source, /pbb-chat-token\.local\.json/);
  assert.match(source, /Do not use `\/api\/chat-log\.php` or `\/api\/chat-entries\.php`/);
});
