# User stories: what people want to accomplish

These illustrative stories describe individual goals in Syndicatum. They are
not customer testimonials or promises of a particular outcome. [Use cases](use-cases.md)
explain where the product fits; [fictional workdays](day-in-the-life.md) show how
people might use it over time.

The personas below describe work roles, not automatic permission grants.
Syndicatum project membership and permissions determine available actions.

## Leaders: understand what needs a decision

### Persona

A team leader responsible for the direction of a shared project.

### Situation

Several contributors report progress, but one deliverable still depends on an
unresolved decision.

### User story

As a leader, I want to see ownership, progress, and unresolved questions together
so that I can decide where my attention is needed.

### What Syndicatum provides

A shared timeline, assigned tasks, and a plan connecting tasks to deliverables
and milestones. Authorized owners can also inspect Project status.

### Expected outcome

The leader can identify the question and record a decision with its rationale.
Reported progress remains evidence to review, not proof that every result is acceptable.

## Assistants: turn a discussion into clear follow-up

### Persona

An assistant helping a manager prepare and follow through on meetings.

### Situation

A discussion produces requests for several people, and the manager needs a
reliable account of what remains outstanding.

### User story

As an assistant, I want to record requests with clear recipients and track the
resulting work so that follow-up does not depend on my memory.

### What Syndicatum provides

Replies and direct addressing, action requests in the Responsibility Inbox,
and shared tasks with activity history, subject to the assistant's permissions.
A Work request distinguishes Awaiting work, In progress, and Awaiting review;
the requester can Accept work or Request changes after submission.

### Expected outcome

The assistant can distinguish a received message from an unfinished task and
bring unresolved items back to the manager without claiming decisions on their behalf.

## Coordinators: keep handoffs visible

### Persona

A project coordinator arranging work across contributors.

### Situation

One person's draft is ready, but another person must review it before the next
piece of work can proceed.

### User story

As a coordinator, I want to see who owns the next step so that a handoff does not
get lost among general updates.

### What Syndicatum provides

Task assignees, lifecycle states, source-message references, and deliverables
that group related tasks. The timeline holds the handoff discussion.

### Expected outcome

The coordinator can identify and communicate the next action. Contributors still
need to agree on sequencing; grouping tasks does not automatically execute a workflow.

## Developers: make review evidence easy to find

### Persona

A developer contributing a change to a team project.

### Situation

An implementation is ready for review, with a pull request and verification results
stored in the team's development tools.

### User story

As a developer, I want to connect my task to the review evidence so that the
reviewer can assess the actual change and its limits.

### What Syndicatum provides

Task activity, timeline replies, and artifact references on deliverables.
Configured integrations can also record external events as System Messages.

### Expected outcome

The reviewer can find the change and ask informed questions. Repository access,
merge permission, and deployment decisions remain in the appropriate authorized workflow.

## Marketing and communications contributors: work from an agreed brief

### Persona

A contributor drafting content for a shared campaign or announcement.

### Situation

Several people are creating assets, and the allowed claims must stay consistent.

### User story

As a communications contributor, I want the audience, brief, and review requirements
in one project context so that I can prepare material the team can evaluate.

### What Syndicatum provides

Shared operating instructions, assigned drafting and review tasks, and a timeline
for feedback and links to working artifacts. The message picker can attach
existing Project Files or upload into the selected folder. These hosted files
have public links; restricted drafts belong in an appropriately controlled
external service. See [Project files](project-files.md) for access and limits.

### Expected outcome

The contributor can resolve feedback against the agreed brief. A human reviewer
still checks factual claims, and publication happens through authorized channels.

## Operations: coordinate a response without confusing signals with decisions

### Persona

An operations contributor responding to a service issue.

### Situation

An external system reports a warning while colleagues are investigating different
possible causes.

### User story

As an operations contributor, I want a shared record of observations and assigned
investigations so that I can act on confirmed information.

### What Syndicatum provides

Integration System Messages, project discussion, direct requests, and task history.
Realtime updates help connected participants follow changes.

### Expected outcome

Responders can separate an incoming signal from a verified finding. A notification
or external event does not authorize a restart, remediation, or other operational action.

## Researchers: separate findings from assumptions

### Persona

A researcher preparing evidence for a team decision.

### Situation

An AI-generated synthesis includes a useful lead but lacks support for one claim.

### User story

As a researcher, I want to record sources and unanswered questions alongside my
assignment so that the final recommendation can be checked.

### What Syndicatum provides

Project criteria, research tasks, artifact links, and discussion with reviewers.
Agents can contribute within their configured roles and authorized access.

### Expected outcome

The researcher can present verified findings and remaining uncertainty separately.
Syndicatum does not verify source truth merely because a summary appears on the timeline.

## Reviewers: preserve the reason for requesting changes

### Persona

A reviewer assessing a proposed project change or a submitted work product.

### Situation

A proposal is useful but needs a clearer scope before it should be applied.

### User story

As a reviewer, I want to inspect the proposal and record my reasoning so that the
next contributor understands what must change.

### What Syndicatum provides

Authorized project owners and administrators can review AI setup and plan proposals
with a recorded decision. Task history and timeline replies support work-product feedback.

### Expected outcome

The team can distinguish a pending proposal, feedback, and an applied change.
Being named as a reviewer does not itself grant administrative approval permissions.

## Administrators: configure participation within the right scope

### Persona

An authorized project administrator setting up an AI participant.

### Situation

The team wants assistance with documentation but needs a defined role and supervisor.

### User story

As a project administrator, I want to configure the agent's role and supervision
so that its responsibilities are explicit before it starts contributing.

### What Syndicatum provides

Project-scoped agent management, role instructions, supervision, and separate
credential and conversation-notification setup workflows for supported integrations.

### Expected outcome

The team can identify what the agent is expected to do and who supervises it.
Creating a profile is separate from authorizing its runtime or granting external file access.

## New participants: learn the project before contributing

### Persona

A colleague joining an existing project.

### Situation

The project already has decisions, assignments, and linked materials that the
new participant needs to understand.

### User story

As a new participant, I want to read the project context and ask focused questions
so that my first contribution fits the team's current work.

### What Syndicatum provides

Shared instructions, a project timeline, task records, and links to deliverable
artifacts visible within the participant's authorized project access.

### Expected outcome

The colleague can identify the right next step and ask for missing information.
Project membership does not automatically grant access to linked documents;
messages addressed to one person remain visible to the project's participants.
