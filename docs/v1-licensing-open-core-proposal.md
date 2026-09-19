# Syndicatum V1 Licensing and Open-Core Proposal

**Proposal date:** 2026-09-16
**Status:** Accepted product direction and applied to the repository; subject to legal review before public release
**Applies to:** Syndicatum self-hosted V1 and its initial commercial packaging
**Product-strategy contributors:** Syndicatum Developer and Commercial Assessor

## Executive decision

Release the complete, genuinely useful self-hosted Syndicatum V1 under
**GNU Affero General Public License v3.0 only** (`AGPL-3.0-only`).

Syndicatum should initially earn revenue from operating and supporting the open
coordination layer rather than withholding the essential coordination model.
The first commercial offers should therefore be managed hosting, deployment and
migration assistance, backups and upgrades, reliability commitments, custom
integrations, and enterprise support.

Do not introduce proprietary product modules or dual licensing at launch unless
an actual customer requirement justifies the additional complexity. Use the
first 5–10 design-partner pilots to identify which enterprise capabilities
customers will pay for and whether AGPL creates recurring procurement friction.

This is a product and packaging proposal, not legal advice. Qualified counsel
should approve the license notices, dependency compliance, contributor terms,
trademark policy, and any later commercial or dual-license agreement.

## Why AGPL-3.0-only

Syndicatum is network-interactive server software. AGPL is strategically aligned
with the project because its network-use provision is intended to require an
operator of a modified version to offer the corresponding source to users who
interact with that modified program over a network.

The commercial objective is not to prevent legitimate self-hosting or external
integrations. It is to reduce the risk that another operator privately modifies
the coordination server, offers it as a closed hosted service, and contributes
nothing back.

Use `AGPL-3.0-only`, rather than `AGPL-3.0-or-later`, so the project does not
automatically grant recipients the option to apply a future license version that
the copyright holder has not reviewed. Counsel should confirm this choice.

### Principal trade-off

AGPL can create procurement friction. Some enterprises, platform vendors, and
ecosystem partners have policies that discourage or prohibit AGPL software even
when deployed as an independent service.

That risk should be measured rather than assumed. Syndicatum should not switch
to a permissive license pre-emptively. If otherwise-qualified design partners
repeatedly identify AGPL as the specific reason they cannot adopt the product,
the project should evaluate a commercial license or a different licensing model
using that evidence.

Apache-2.0 remains the principal alternative if maximizing embedding and
ecosystem adoption becomes more important than protection from closed hosted
derivatives. It is not the recommended V1 choice.

## Open self-hosted V1 boundary

The open distribution must be capable of proving Syndicatum's core product
claim without paid extensions. It should include:

- the application server and user interface;
- database schema, migrations, and standard deployment assets;
- Project API V1 and remote MCP support;
- projects and participant identities;
- the canonical project timeline;
- direct and broadcast addressing;
- replies and acknowledgements;
- the Responsibility Inbox;
- basic Delivery & Binding Health;
- documented backup, restore, upgrade, and health procedures; and
- standard supported participation paths for Codex, ChatGPT, and Gemini.

The open version must not be intentionally crippled through artificial limits
on the coordination semantics that demonstrate the product's value.

## Initial paid offering

Commercial value should initially come from operational trust and service around
the open product:

- managed Syndicatum hosting;
- installation and migration services;
- managed backups, recovery, and upgrades;
- monitoring and reliability operations;
- service-level commitments;
- priority and enterprise support;
- customer-specific integrations; and
- training and implementation assistance.

These services let customers pay for reduced operational burden while preserving
a credible self-hosted product and community.

## Capabilities that may become proprietary later

Do not commit these capabilities to a proprietary edition before pilot evidence
shows real demand. Plausible candidates include:

- high availability and disaster-recovery orchestration;
- SAML, SCIM, and enterprise identity provisioning;
- advanced retention, legal hold, and compliance controls;
- organization-wide governance and policy management;
- fleet-wide health, operations, and analytics;
- premium enterprise connectors; and
- enterprise support tooling and contractual assurances.

The boundary test is straightforward: the open product must retain normal human
and agent coordination, while a paid capability should principally solve an
enterprise-scale operational, governance, compliance, or support problem.

## Integration boundary

Use strong copyleft for the coordination server while keeping its public
protocol boundaries easy to integrate with.

- Publish stable HTTP, MCP, and future standards-compatible interfaces.
- Keep external clients and agents able to communicate through documented APIs
  without requiring modification of the server codebase.
- Keep provider-specific behavior in adapters rather than contaminating the
  provider-neutral coordination contract.
- Document which components are part of the AGPL program and how separately
  operated clients communicate with it.
- Do not promise that a particular integration pattern is legally outside the
  AGPL without counsel's review; architecture documentation is not a substitute
  for a license opinion.

## Future commercial licensing

Do not launch dual licensing by default. Consider it later when a qualified
customer needs to embed or modify Syndicatum under terms incompatible with
AGPL, or when repeated procurement evidence shows that AGPL materially blocks
the target market.

A possible later model is:

1. the self-hosted product remains available under `AGPL-3.0-only`; and
2. the copyright holder offers a separate commercial license to approved
   customers under negotiated terms.

This option depends on clean copyright ownership. It must not be promised until
the project can demonstrate that it has authority to license all covered code
commercially.

## Copyright and contribution governance

Before accepting material external contributions or promising dual licensing:

- inventory the copyright owners of the existing codebase;
- confirm that all incorporated code and assets have compatible licenses;
- choose and publish a transparent contribution policy;
- obtain legal advice on whether to use a contributor license agreement,
  copyright assignment, or another inbound contribution mechanism;
- record provenance for future contributions; and
- avoid accepting code whose licensing would prevent the intended distribution
  or future commercial-license option.

Ordinary inbound-equals-outbound contributions may leave copyright with each
contributor. In that case, relicensing the complete codebase later can require
permission from those contributors. The governance model must address that risk
without surprising contributors.

## Trademark boundary

Code licensing and brand licensing are separate.

The project should reserve and protect the **Syndicatum** name and marks, then
publish a trademark policy describing acceptable community use. Forks may have
the software freedoms provided by AGPL without automatically gaining the right
to present themselves as an official or compatible Syndicatum distribution.

## Publication and compliance requirements

Before the V1 public release:

- [ ] Obtain legal review of this proposed structure.
- [ ] Confirm copyright ownership and contribution provenance.
- [ ] Complete a dependency and asset license inventory.
- [x] Add the official AGPLv3 license text as `LICENSE`.
- [x] Declare the SPDX identifier `AGPL-3.0-only` in repository documentation.
      Add it to future package metadata formats that support a license field.
- [ ] Add appropriate copyright and license notices to source files or package
      metadata as counsel recommends.
- [ ] Document how users of a hosted modified version can obtain corresponding
      source when the license requires it.
- [ ] Publish contribution and trademark policies.
- [ ] Clearly identify any separately licensed components or services.
- [ ] Include license compliance in the release checklist.

## Pilot evidence and reconsideration trigger

During the first 5–10 design-partner pilots, record:

- whether the prospect is otherwise qualified;
- whether self-hosting or managed hosting is preferred;
- whether legal or procurement review objects specifically to AGPL;
- whether the objection can be resolved through architecture clarification;
- whether a commercial license would resolve it; and
- whether the opportunity was delayed or lost because of licensing.

Reconsider the licensing model only when qualified adoption lost specifically
because of AGPL becomes a recurring pattern rather than a hypothetical concern.
At that point, compare at least these options:

1. retain AGPL with no change;
2. retain AGPL and add a commercial dual-license offer;
3. adjust component boundaries while preserving an AGPL server core; or
4. adopt a more permissive license such as Apache-2.0.

Any change must include legal review, compatibility analysis, contributor
authorization, and a clear migration plan.

## Recommended decision record

> Syndicatum will release its complete self-hosted V1 under
> `AGPL-3.0-only`. The open distribution will contain the essential coordination
> server, interfaces, timeline semantics, responsibility surfaces, baseline
> operational health, and standard agent participation paths. Initial revenue
> will come from managed operations, implementation, reliability, integrations,
> and support. Proprietary enterprise modules or commercial dual licensing will
> be introduced only in response to validated customer demand. Before release,
> Syndicatum will establish copyright, contribution, dependency, and trademark
> governance and obtain qualified legal review.

## Authoritative license references

- [GNU Affero General Public License v3.0](https://www.gnu.org/licenses/agpl-3.0.html)
- [GNU license list and AGPL explanation](https://www.gnu.org/licenses/)
- [GNU guidance for applying GPL-family licenses](https://www.gnu.org/licenses/gpl-howto.en.html)
- [Apache License 2.0](https://www.apache.org/licenses/LICENSE-2.0)
- [Open Source Definition](https://opensource.org/osd)
