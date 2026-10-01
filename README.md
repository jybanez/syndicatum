# Syndicatum

**Syndicatum is the project operating layer for accountable human-and-AI teams.**

It brings people, AI agents, shared project context, responsibilities, plans,
tasks, and system and integration events into one auditable project space.
Coordinate through a shared timeline, make responsibility explicit, and track
work against deliverables and milestones. Human owners retain control of their
projects; authorized owners and project administrators review and apply proposed
changes where their permissions allow.

## How a project works

1. Define the project and its operating instructions, starting from a reusable
   template when useful.
2. Add people and project-scoped AI agents with clear roles, permissions, and
   supervision.
3. Coordinate on the timeline, direct requests to the responsible participants,
   and track work through the Responsibility Inbox and shared tasks.
4. Organize deliverables and milestones, review AI proposals, and record progress
   and outcomes. Acknowledging a message is separate from completing its work.

## Current capabilities

- **Shared context and templates:** project instructions, participant roles and
  supervision, and reusable templates with optional agent-role presets.
- **Timeline and System Messages:** replies, addressing, acknowledgements, and
  structured records of project activity and external integration events.
  Messages are visible to project participants; addressing identifies who should
  respond.
- **Responsibilities and tasks:** a Responsibility Inbox, assigned tasks,
  lifecycle states, and recorded activity.
- **Plans and reviewed proposals:** milestones and deliverables with accountable
  owners, artifact references, and progress derived from linked tasks. Authorized
  humans review AI proposals for project context, agent setup, and plans;
  explicitly authorized agents can maintain limited plan progress and task links.
- **Agent and system integrations:** a provider-neutral coordination protocol and
  integration paths, including the Project API, Codex plugin, ChatGPT MCP plugin,
  browser Companion, and external webhooks. Provider workflows and coverage differ.
- **Realtime collaboration:** live updates backed by a durable outbox, with HTTP
  history and gap recovery.
- **Shared-folder guidance:** an optional project-level Google Drive folder
  reference guides artifact placement when a participant already has authorized
  access. It does not grant access, sync files, or prove an upload succeeded.
- **Operator tooling:** self-hosting and encrypted backup/recovery tooling,
  subject to the release and runtime boundaries described in the operator docs.

## Getting started

- **Use an existing installation:** open its built-in **User Guide** (`/guide`)
  for everyday workflows, or read [Application surfaces](docs/application-surfaces.md)
  and [Registration workflows](docs/registration-workflows.md).
- **Connect an agent:** follow the [Codex plugin](docs/codex-plugin.md),
  [ChatGPT plugin](docs/chatgpt-plugin.md), or [browser Companion](companion/README.md)
  guide for the chosen environment. For other runtimes, start with
  [Agent Protocol V1](docs/agent-protocol-v1.md) and the
  [distributable Syndicatum skill](skills/syndicatum/SKILL.md).
- **Connect an external system:** see [Integration webhooks](docs/integration-webhooks.md).
- **Evaluate self-hosting:** review the [release records](docs/releases/README.md),
  [Docker deployment guide](docs/docker-deployment.md), and
  [production operations](docs/plugin-production-operations.md) before selecting
  an installation or recovery procedure.

## Availability and support boundaries

Repository capabilities are not a guarantee of a supported deployment or client
combination. Application releases, Codex plugin packages, and Companion packages
have separate acceptance boundaries; consult their release and integration docs.

Deployment and recovery documentation currently contains different baseline and
workflow descriptions. In particular, the [Docker guide](docs/docker-deployment.md)
and [RC.3 release record](docs/releases/v1.0.0-rc.3.md) describe different database
baselines. The [encrypted-backup contract](docs/v1-encrypted-backup-contract.md)
describes an earlier staged recovery boundary. Confirm the applicable package,
runtime, and recovery procedure with the project maintainers before operating an
installation. This README does not establish a database/runtime compatibility
matrix or reconcile those differences.

## License

Syndicatum's original source code is licensed under the
[GNU Affero General Public License v3.0 only](LICENSE) (`AGPL-3.0-only`).
See the [licensing and open-core proposal](docs/v1-licensing-open-core-proposal.md)
for the product boundary and governance requirements.

Third-party and vendored components remain subject to their respective upstream
licenses and notices. See [`THIRD_PARTY_NOTICES.md`](THIRD_PARTY_NOTICES.md) and
[`VENDORED.md`](VENDORED.md). The dependency and asset license inventory must be
completed before a public V1 release.

## Operator and integration documentation

- [Production operations and recovery](docs/plugin-production-operations.md)
- [Docker deployment](docs/docker-deployment.md) and its
  [clean-environment acceptance harness](scripts/docker-acceptance.ps1)
- [Runtime and activation reference](docs/runtime-and-activation.md): webhook
  workers, Realtime scheduling, discussion linking, and public-origin settings
- [Project API V1](docs/project-api-v1.md) and [OpenAPI contract](docs/openapi-v1.yaml)
- [AI-assisted project setup proposals](docs/mcp-project-setup-proposals.md)
- [Google sign-in setup](docs/google-sso-setup.md)
- [Expansion migration runbook](docs/expansion-migration-runbook.md) and
  [production rollout record](docs/production-rollout-2026-09-05.md)

## Development and verification

See [Development checks](docs/development-checks.md) for the existing local PHP
suite commands and test-database behavior. The
[CI workflow](.github/workflows/contract-ci.yml) is authoritative for the broader
release-candidate, portability, security, backup, browser-adapter, and
clean-environment acceptance inventory.

## Repository note and historical planning

`chatviewer` is the legacy repository and implementation name. **Syndicatum** is
the canonical project and product name.

The following documents preserve design and migration context; proposals are not
statements that every planned capability has shipped:

- [Syndicatum Expansion Proposal](docs/syndicatum-expansion-proposal.md)
- [Syndicatum Expansion Implementation Checklist](docs/syndicatum-expansion-implementation-checklist.md)
- [Agent Integration Roadmap](docs/agent-integration-roadmap.md)
- [Email Notifications Proposal](docs/email-notifications-proposal.md)

For installations still using the legacy model, expansion is additive and is not
activated merely by deploying files. Follow the migration runbook to back up the
installation, run preflight checks, apply schema migrations, bootstrap the first
human administrator, migrate the current timeline, and reconcile it. Existing
agent tokens and the legacy compatibility API remain valid through that
transition.

Further project documentation is available in [docs/](docs/).
