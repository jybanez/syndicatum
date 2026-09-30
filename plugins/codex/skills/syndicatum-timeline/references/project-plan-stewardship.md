# Project-plan stewardship

Use plan stewardship when an owner has explicitly granted the agent permission
to keep an approved project plan current as task work progresses. This is an
operational role, not project administration.

## Read before updating

1. Call `syndicatum_get_bootstrap` and confirm
   `permissions.plan.progress.update` is true.
2. Call `syndicatum_get_project_plan` immediately before changing a status.
   Use the returned milestone or deliverable ID and latest `version`.
3. Read the relevant tasks when the update depends on task progress. Do not
   infer completion from an old message or from assignment alone.

## Apply narrow progress changes

- Use `syndicatum_update_milestone_progress` only for milestone status.
- Use `syndicatum_update_deliverable_progress` only for deliverable status.
- Always provide a concise evidence note naming the completed work, task IDs,
  blocker, review result, or owner decision that supports the transition.
- Treat a version conflict as evidence that the plan changed: reload the plan,
  reassess, and do not blindly replay the old mutation.
- A milestone cannot be completed while any active deliverable is not approved
  or completed. A deliverable cannot be approved or completed while a linked
  active task is incomplete.

These tools cannot change titles, descriptions, owners, dates, hierarchy, or
ordering; structural changes remain proposals. Use
`syndicatum_propose_project_plan` when the plan needs new
milestones or deliverables. Ask an owner or administrator to edit other
structural fields.

## Useful scenarios

- An executive-assistant agent sees all linked design tasks completed and moves
  the “Event poster” deliverable from `in_review` to `completed`, citing the
  review task and approval message.
- A dependency slips. The agent marks the affected deliverable `blocked` with a
  specific blocker note and marks its milestone `at_risk`, without rearranging
  the plan.
- A review task is reopened. The agent moves the deliverable back from
  `in_review` to `in_progress` and records why.
- Weekly coordination confirms that every deliverable in a milestone is ready.
  The agent completes the milestone using the current version returned by the
  plan read.
