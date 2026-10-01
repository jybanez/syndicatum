import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import vm from "node:vm";

const source = readFileSync(new URL("../assets/app.mjs", import.meta.url), "utf8");
const requestSource = source.slice(source.indexOf("async function request("), source.indexOf("function redirectWithBusy("));
const querySource = source.slice(source.indexOf("function projectStatusQuery("), source.indexOf("function openProjectStatusModal("));
let response;
const context = vm.createContext({
  URLSearchParams, API: { projectStatusPlan: "plan" }, selectedProjectId: () => 7,
  unwrap: (value) => value?.data ?? value,
  fetch: async (_url, options) => { assert.equal(options.requireJson, undefined); return response; },
});
vm.runInContext(requestSource + querySource, context);
const query = () => context.projectStatusQuery("plan", {}, undefined);
response = { ok: true, json: async () => { throw new SyntaxError("HTML fatal error"); } };
await assert.rejects(query(), /invalid project status response/);
for (const payload of [null, {}, { data: {} }, { data: { project_id: 99 } }, { data: { project_id: 7 } }]) {
  response = { ok: true, json: async () => payload };
  await assert.rejects(query(), /invalid project status response|incomplete project plan totals/);
}
response = { ok: false, status: 403, json: async () => ({ message: "Forbidden" }) };
await assert.rejects(query(), /Forbidden/);
for (const total of [0, 3]) {
  const data = { project_id: 7, milestones: { total }, deliverables: { total: total * 2, ready: total, blocked: total } };
  response = { ok: true, json: async () => ({ data }) };
  assert.equal(await query(), data);
}
console.log("Project status client: malformed responses rejected, valid empty and populated plans preserved.");
