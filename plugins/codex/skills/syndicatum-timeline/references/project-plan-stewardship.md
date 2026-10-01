# Project-plan stewardship

Use plan stewardship when an owner has explicitly granted the agent permission
to keep an approved project plan current as task work progresses. This is an
operational role, not project administration.

## Read before updating

1. Call `syndicatum_get_bootstrap` and confirm
   `permissions.plan.progress.update` is true.
2. Call `syndicatum_get_project_plan` immediately before changing a task link
   or status. Use the returned milestone or deliverable ID and latest version.
3. Read the relevant tasks when the update depends on task progress. Do not
   infer completion from an old message or from assignment alone.

## Apply narrow progress changes

- Use `syndicatum_update_task_deliverable` to link existing work to the
  deliverable it produces, or to unlink an incorrect association. Use the
  task's latest `version`; this operation cannot change its assignee, task
  giver, priority, due date, or lifecycle status.
- Use `syndicatum_update_milestone_progress` only for milestone status.
- Use `syndicatum_update_deliverable_progress` only for deliverable status.
- Always provide a concise evidence note naming the completed work, task IDs,
  blocker, review result, or owner decision that supports the transition.
- Treat a version conflict as evidence that the plan changed: reload the plan,
  reassess, and do not blindly replay the old mutation.
- A milestone cannot be completed while any active deliverable is not approved
  or completed. A deliverable cannot be approved or completed while a linked
  active task is incomplete. Linking an incomplete task to an already approved
  or completed deliverable is rejected; move the deliverable back to an active
  status first when the new work genuinely reopens it.

These tools cannot change titles, descriptions, owners, dates, hierarchy, or
ordering; structural changes remain proposals. Use
`syndicatum_propose_project_plan` when the plan needs new
milestones or deliverables. Ask an owner or administrator to edit other
structural fields.

## Useful scenarios

- An executive-assistant agent sees all linked design tasks completed and moves
  the “Event poster” deliverable from `in_review` to `completed`, citing the
  review task and approval message.
- Work was created before the plan existed. The executive assistant links each
  existing task to the approved deliverable it produces, citing the task scope,
  then updates the deliverable status from the reconciled task evidence.
- A dependency slips. The agent marks the affected deliverable `blocked` with a
  specific blocker note and marks its milestone `at_risk`, without rearranging
  the plan.
- A review task is reopened. The agent moves the deliverable back from
  `in_review` to `in_progress` and records why.
- Weekly coordination confirms that every deliverable in a milestone is ready.
  The agent completes the milestone using the current version returned by the
  plan read.
