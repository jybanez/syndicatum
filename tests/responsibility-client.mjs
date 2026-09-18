import test from "node:test";
import assert from "node:assert/strict";
import { responsibilityActions, responsibilityEvent } from "../assets/responsibility-inbox.mjs";

const request = {
  project_id: 2,
  request_message_id: 100,
  requester_participant_id: 10,
  initial_responder_participant_id: 20,
  current_responder_participant_id: 20,
  last_responder_participant_id: 20,
  state: "open",
  blocked: false,
  work_started: false,
  latest_evidence_message_id: 100,
};

test("actor-specific actions do not treat mention or arbitrary membership as authority", () => {
  const active = [10, 20, 30, 40];
  assert.deepEqual(responsibilityActions(request, 30, false, active), []);
  assert.deepEqual(responsibilityActions(request, 20, false, active),
    ["work_started", "blocked", "resolution_proposed", "transfer_offered"]);
  assert.deepEqual(responsibilityActions(request, 10, false, active),
    ["transfer_offered", "request_withdrawn"]);
  assert.deepEqual(responsibilityActions(request, 40, true, active),
    ["transfer_offered", "request_withdrawn"]);
  assert.deepEqual(responsibilityActions({ ...request, state: "unknown" }, 20, true, active), []);
});

test("client actions use exact persisted evidence references", () => {
  const blocked = { ...request, blocked: true, block_event_message_id: 104,
    latest_evidence_message_id: 106 };
  assert.deepEqual(responsibilityEvent(blocked, "unblocked"), {
    kind: "unblocked", request_message_id: 100,
    initial_responder_participant_id: 20,
    expected_event_id: 106, reference_event_id: 104,
  });
  assert.deepEqual(responsibilityEvent(request, "transfer_offered", 30), {
    kind: "transfer_offered", request_message_id: 100,
    initial_responder_participant_id: 20,
    expected_event_id: 100, target_participant_id: 30,
  });
  assert.throws(() => responsibilityEvent(request, "unblocked"), /reference is unavailable/);
  assert.throws(() => responsibilityEvent(request, "transfer_offered"), /Choose an active/);
});

test("pending decisions and orphaned restoration follow explicit actor and target rules", () => {
  const transfer = { ...request, state: "transfer_pending",
    current_responder_participant_id: null, pending_event_message_id: 110,
    pending_target_participant_id: 30, latest_evidence_message_id: 110 };
  assert.deepEqual(responsibilityActions(transfer, 30, false, [10, 20, 30]),
    ["transfer_accepted", "transfer_declined"]);
  assert.equal(responsibilityEvent(transfer, "transfer_accepted").reference_event_id, 110);
  const resolution = { ...request, state: "resolution_pending",
    pending_event_message_id: 115, pending_proposer_participant_id: 20,
    latest_evidence_message_id: 115 };
  assert.deepEqual(responsibilityActions(resolution, 10, false, [10, 20]),
    ["resolution_accepted", "resolution_disputed", "request_withdrawn"]);
  assert.deepEqual(responsibilityActions(resolution, 20, false, [10, 20]),
    ["resolution_withdrawn"]);
  assert.equal(responsibilityEvent(resolution, "resolution_disputed").reference_event_id, 115);
  const orphaned = { ...request, state: "orphaned",
    current_responder_participant_id: null };
  assert.deepEqual(responsibilityActions(orphaned, 10, false, [10, 20, 30]),
    ["transfer_offered", "request_withdrawn", "responder_restored"]);
  assert.deepEqual(responsibilityActions(orphaned, 10, false, [10, 30]),
    ["transfer_offered", "request_withdrawn"]);
});
