import test from "node:test";
import assert from "node:assert/strict";
import {
  settleResponsibilityAction,
  validationAlertItems,
} from "../assets/responsibility-action-flow.mjs";

test("single and multiple validation issues retain concise user-facing labels", () => {
  assert.deepEqual(validationAlertItems({ errors: { note: "Reason or evidence note — required" } }, {
    note: "Reason or evidence note",
  }), ["Reason or evidence note — required"]);
  assert.deepEqual(validationAlertItems({ errors: {
    note: "Reason or evidence note — required",
    target_participant_id: "Active handoff target — required",
  } }, {
    note: "Reason or evidence note",
    target_participant_id: "Active handoff target",
  }), ["Reason or evidence note — required", "Active handoff target — required"]);
});

test("a delayed successful POST suppresses hooks after a project switch", async () => {
  let current = true;
  let resolvePost;
  let successCalls = 0;
  const pending = settleResponsibilityAction({
    submit: () => new Promise((resolve) => { resolvePost = resolve; }),
    isCurrent: () => current,
    onSuccess: () => { successCalls += 1; },
  });
  current = false;
  resolvePost({ id: 200 });
  assert.deepEqual(await pending, { outcome: "success", current: false, value: { id: 200 } });
  assert.equal(successCalls, 0);
});

test("a delayed 409 suppresses conflict hooks after a project switch", async () => {
  let current = true;
  let rejectPost;
  let conflictCalls = 0;
  const conflict = Object.assign(new Error("Conflict"), { status: 409 });
  const pending = settleResponsibilityAction({
    submit: () => new Promise((_resolve, reject) => { rejectPost = reject; }),
    isCurrent: () => current,
    onConflict: () => { conflictCalls += 1; },
  });
  current = false;
  rejectPost(conflict);
  const result = await pending;
  assert.equal(result.outcome, "conflict");
  assert.equal(result.current, false);
  assert.equal(result.error, conflict);
  assert.equal(conflictCalls, 0);
});

test("hooks receive a live lifecycle guard and stale completion remains non-current", async () => {
  let current = true;
  let postHookEffects = 0;
  const result = await settleResponsibilityAction({
    submit: async () => ({ id: 201 }),
    isCurrent: () => current,
    async onSuccess(_value, lifecycle) {
      current = false;
      await Promise.resolve();
      if (lifecycle.isCurrent()) postHookEffects += 1;
    },
  });
  assert.equal(result.current, false);
  assert.equal(postHookEffects, 0);
});
