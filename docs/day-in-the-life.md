# A day in the life with Syndicatum

**All people and situations below are fictional.** These are illustrative workdays,
not customer accounts, measured results, or promises of seamless automation.
Each person uses a configured installation with the permissions needed for the
actions described. Their job title alone does not grant those permissions.

[Use cases](use-cases.md) describe patterns of work. [User stories](user-stories.md)
describe individual goals. These narratives show the interruptions and judgments
that can sit between a request and a useful outcome.

## Mara, an assistant preparing a review meeting

**Morning.** Mara opens the project timeline to prepare a manager's review agenda.
An AI assistant has summarized recent discussion, but one item sounds like an
approved decision when the original reply only suggested an option. Mara checks
the thread and corrects the agenda draft before sharing its link.

**Midday.** With permission to create tasks, she records the agreed preparation
work and names the responsible contributors. She directly addresses a question
to the person who owns a missing figure. The message is visible to the project;
she keeps confidential personnel matters in the team's appropriate separate system.

**Afternoon.** A contributor acknowledges the request, but the figure has not
arrived. Mara leaves the preparation task open and tells the manager what is
still missing. Acknowledgement shows that the message was handled; it does not
substitute for delivery of the requested work. The meeting can proceed with the
gap clearly recorded instead of hidden by a polished summary.

## Luis, a project manager adjusting a plan

**Morning.** Luis reviews tasks linked to a deliverable for a small program.
Most work is complete, but the person preparing the final materials reports a
delay. The task count alone does not tell him whether the milestone is ready.
He reads the update and speaks with the responsible contributor.

**Midday.** An AI planning assistant proposes a new milestone and deliverable
structure. Luis, who is an authorized project administrator, reviews it. The
suggestion duplicates an existing deliverable, so he rejects it with a reason
rather than applying a plan that would confuse ownership. He then uses the
existing plan controls to make the agreed adjustment within his permissions.

**Afternoon.** He records the changed expectations on the timeline and asks the
reviewer to confirm the revised handoff. Another contributor has already started
unaffected work. Luis keeps that work visible and leaves the delayed task open.
The day ends with a usable plan and an explicit unresolved item, not a claim that
an AI proposal has removed the delay.

## Asha, a team leader reviewing a recommendation

**Morning.** Asha compares a research recommendation with the project's decision
criteria. An AI summary favors one option, but the linked evidence does not
support its cost assumption. She asks the researcher to separate verified figures
from estimates before the team makes a commitment.

**Midday.** She reviews the project's deliverables and assigned work with the team.
A completed research task means its author has finished the assignment; it does
not compel her to accept its conclusion. Asha records the missing evidence and
agrees on a narrower follow-up instead of reopening every completed task.

**Afternoon.** The researcher supplies a corrected comparison. Asha records a
conditional decision, including the question that still needs an external answer.
An authorized colleague will handle the next external action through the team's
normal process. The timeline preserves why the team chose that direction, including
its uncertainty, without pretending a project decision grants spending or signing authority.

## Ben, a developer handing a change to a reviewer

**Morning.** Ben finds a task requesting a documentation fix and checks its scope
before editing. The requester also sent a Work request: Ben chooses **Start
work**, moving it from Awaiting work to In progress. He prepares the change in
the team's repository workflow, then chooses **Submit for review** with the
pull-request link and verification notes. The request now awaits review.

**Midday.** A configured integration posts a successful build event as a System
Message. Ben reads it as evidence from the external system. It is an FYI event,
not a new instruction to merge. The reviewer spots an ambiguous sentence despite
the passing check. As the requester, the reviewer chooses **Request changes**
with a note explaining the revision needed.

**Afternoon.** Ben updates the wording, reruns the relevant check, and records the
new revision through **Submit for review**. The requester still needs to choose
**Accept work** after checking it. Ben leaves the separate task in review rather
than treating the build notification as completion. The authorized repository
maintainer will make the merge decision. The handoff contains the evidence and the remaining decision,
so the next person does not have to infer either from scattered chats.

## Noor, a communications contributor revising an announcement

**Morning.** Noor reads the project brief before drafting an announcement. An AI
assistant offers a sentence promising broader support than the brief allows.
She removes it and asks the factual reviewer about a narrower statement.

**Midday.** A design contributor posts an external shared-folder artifact link,
but Noor cannot open the file. The project reference has not granted her access.
She asks the document's owner for the appropriate permission instead of copying it into a more widely
shared location. While waiting, she works on the text that is already available.

**Afternoon.** The factual reviewer answers, and Noor updates the draft and its
task record. She attaches a shareable draft from the project file picker after
checking that it is suitable for anyone with its public link to read; the
restricted source stays in the external service. A separate authorized publisher
still needs to review the final asset and publish it through the chosen channel. Noor records that handoff.
The project now shows what is ready and what is waiting; it has not silently
published content or changed anyone's document permissions.

## Evan, an operations contributor investigating a warning

**Morning.** An external monitoring integration posts a warning to the project.
Evan receives a notification and opens the authoritative event and discussion.
He checks with the incident lead because a similar warning was previously caused
by delayed reporting. Notification delivery is not confirmation of the cause.

**Midday.** The lead assigns investigation tasks. Evan compares the signal with
current observations from systems he is authorized to access and posts a concise
finding without credentials or restricted logs. An AI summary helps assemble the
updates, but Evan corrects an unsupported claim that the service has recovered.

**Afternoon.** The authorized responder performs the agreed operational action
in the appropriate system. Evan records fresh verification results; the lead
reviews them before declaring recovery. The team creates follow-up work for the
remaining reporting problem. Syndicatum holds the coordination record, while
monitoring, emergency communication, and operational controls retain their own roles.
