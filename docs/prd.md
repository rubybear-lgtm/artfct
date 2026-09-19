# PRD — artfct for Teams

**Status:** draft for review · **Architecture:** see the [decision record](architecture-decisions.md) — this document does not restate it.

---

## 1. Problem

Coding agents now produce a steady stream of self-contained visual artifacts — dashboards, reports, diagrams, demos, one-off tools. Every major lab ships its own way to share them, and every one of those keeps the artifact inside the lab's ecosystem.

These are **durable work product** — a finished dashboard, a report, a diagram someone will reuse — not chat scrollback. And they are stranded.

Note what this problem is *not*. The labs shipped governance during 2026: Claude Code Artifacts are admin-gated on Team and Enterprise with share permissions, and OpenAI Canvas Sites have RBAC with per-site access modes. Pitching "the labs give you no controls" loses the room in front of a platform lead who has already configured them. See [market-analysis.md](market-analysis.md).

What no lab can fix, because it is structural rather than neglected:

- **Artifacts are trapped per-vendor.** Most engineers run two to four AI tools. A report a PM generated in Cursor is invisible to an engineer in Claude Code, and always will be — Anthropic will not index Cursor's output.
- **Work gets rebuilt.** The next agent cannot see what the last one produced, so it produces it again.
- **Nothing accumulates.** A team's good report formats, dashboard patterns and runbooks stay one-off outputs instead of becoming the way that team works.
- **Non-technical teammates are stuck entirely.** A PM with an HTML file has no way to open, send or place it anywhere their team will look.

**Job to be done:** *"Our people's agents produce real work — across four different tools — and none of it is findable, reusable, or shareable outside the tool that made it."*

Not "we need artifact hosting," and not "we need AI governance."

## 2. Users and buyer

They are not the same person, and this asymmetry shapes the whole GTM.

There are **two user segments, not one**, and `.impeccable.md` said so before this PRD narrowed it: devs are the core audience, "but the tool is simple enough that other professionals (designers, PMs, marketers) who occasionally deal with HTML files would also find it useful."

| | **Technical user** | **Business user** | **Buyer** |
|---|---|---|---|
| Who | Engineer, or their agent acting for them | PM, designer, analyst, marketer — in Cursor or a desktop agent | Engineering or ops lead; security owner joins at Enterprise |
| Produces | Dashboards, demos, diagrams, tooling | Reports, summaries, one-pagers, decks |
| Wants | The deploy stays one call | Somewhere to *put* the thing their agent just made | Their team faster; findable work; a defensible answer at review |
| Reaches us via | CLI, MCP tool | **IT configuring MCP org-wide**, or browser drag-and-drop | — |
| Shares via | link, Slack | **Slack, almost exclusively** | — |
| Status today | **Reachable, not acquired** — CLI, installer, MCP server and skill are built and published, but there is no user base | Same, and further from a CLI than the PRD previously assumed | **Unvalidated** — no buyer conversation has happened |

**Value runs inverse to technical ability.** An engineer with an HTML file has a dozen options. A PM who just generated a report in Cursor has none. That is where "drop it, get a link" is transformative rather than convenient.

**Org-wide MCP configuration is a distribution channel**, not a setup step — one admin action reaches hundreds of people who would never install a CLI.

**There are no users yet.** The distribution *mechanism* exists and works; the distribution itself does not. This is the single most important correction to make when reading the rest of this document: nothing here can lean on an installed base, a friction moment inside an existing team, or organic pull. Both sides of the funnel start from zero, and the buyer has never been tested.

## 3. Positioning

> **Every artifact your team's agents make — findable by your people and your agents, whichever tool made it.**

Three properties, and the combination is what nobody else has:

1. **Cross-vendor.** Claude Code, Cursor, Codex, Copilot — one store. Structurally impossible for any lab: Anthropic indexing Cursor's output is competitively incoherent, not an oversight.
2. **Two audiences, one object.** A teammate opens it from Slack; another agent finds it through retrieval. Memory products serve only agents. Vercel and Tiiny Host serve only humans.
3. **It accumulates.** Teams build up a store of the artifacts that turned out to matter, and agents draw on it.

This is a **capability** pitch, not a compliance one. Compliance competes with controls the labs already shipped; capability competes with nothing. It also changes who buys: an engineering or ops lead who wants their team faster, rather than a security owner working through procurement.

**The claim to make, because it demos in one interaction:** *Codex does not rebuild the dashboard Claude already built.* Not "we transfer understanding" — the artifact is the deliverable, and moving finished work is provable in a way transferring insight is not.

## 4. Goals and non-goals

### Goals
1. An org can point every engineer's agent at its own store with one config change.
2. An admin can see, search, and revoke every artifact the org's agents have produced.
3. Artifacts persist indefinitely with provenance attached — which agent, which model, which repo, which commit.
4. Pass a mid-size company's security review without an exception.
5. Prove that indexed artifacts fed back to agents change how agents work.

### Non-goals — this section does more work than the requirements
- **Not static site hosting.** No custom builds, no frameworks, no `npm run build`.
- **Not a CDN.** Artifacts are shared with tens of people, not millions.
- **Not a Notion or Confluence competitor.** No authoring, no editing, no wiki.
- **Not general file storage.** HTML bundles and their assets. Nothing else at launch.
- **Not non-HTML artifacts at launch** — no notebooks, no PDFs, no video. Adjacent and tempting; explicitly out.
- **Not a document tool.** Broadening to business users pulls straight toward Confluence and Google Docs. Hold the line: **expand the user, not the artifact type.** Interactive HTML an agent produced is what those tools handle badly. The moment this becomes "share any document," we are a worse wiki.
- **Not a memory or context layer.** We move finished artifacts, not reasoning. Different category, different competitors.
- **Not BYOC or self-hosting.** Out of scope, not deferred.

## 5. Requirements, keyed to the build phases

Keyed to the ADR's phases so the two documents stay in sync rather than drifting into competing roadmaps.

**P0 — Team tier, the first thing anyone can pay for**
- Every artifact stamped with full provenance at create time. Required, not optional. *(Client refactor, ships first)*
- Org-scoped API tokens for CLI and MCP, revocable individually. *(Phase 2)*
- Orgs, memberships, invitations, roles — **inherited from the Laravel starter kit's Teams support**, not built. *(Phase 2)*
- Self-serve auth via WorkOS AuthKit — social, passkeys, Magic Auth. *(Phase 2)*
- **Tier upgrade path built in from the start**: `external_identities` table, `auth_mode` state machine (`authkit` → `dual` → `polis`), domain verification before SAML enforcement, and a tested downgrade. *(Phase 2)*
- Dedicated per-tenant deployment, provisioned automatically. *(Phase 1 + 4)*
- Permanent storage with multi-file bundles including JS, well beyond today's 1 MB single-file cap. *(Phase 1 + 3)*
- Admin view: list, filter, search, revoke. *(Phase 2–3)*
- Per-artifact origin isolation, so one org-private artifact cannot read another. *(Phase 3)*

**P1 — Enterprise tier, what turns a Team account into a contract**
- SAML / OIDC SSO. *(Phase 2 — self-hosted Ory Polis)*
- SCIM directory sync and de-provisioning. *(Phase 2 — Ory Polis)*
- Audit log of create / view / share / revoke / delete, exportable to SIEM. *(Phase 4)*
- Retention policy, legal hold, proof of deletion. *(Phase 4)*
- Sharing controls: org-private, link-with-passcode, domain-restricted, expiring, per-link revoke. *(Phase 3)*
- Data residency by deployment region. *(Phase 4)*

**P2 — the thesis**
- Full-text and semantic search across the org's artifacts. *(Phase 5)*
- A retrieval MCP tool: agents query what the org has already built before building it again. *(Phase 5)*
- Provenance-aware answers — "the dashboard from the billing repo, last month." *(Phase 5)*

**P3 — later**
- Tenant-branded domains (`artifacts.acme.com`), a paid add-on.
- Data export tooling beyond the phase-1 baseline.

## 6. Packaging and pricing

### Provisioning is free, so tiers are not gated on tenancy

A dispatch-namespace script plus a D1 database plus an R2 bucket is one API call. The marginal cost of a tenant existing is roughly **$60/year of infrastructure**.

That kills the obvious tier design. There is no reason to put Team customers on shared infrastructure — **every paying tenant gets a dedicated deployment**, at every tier. One tenancy model, no weak-isolation tier growing fastest, no second code path through phases 1–4.

### The tiers are gated on human touch and vendor cost, not compute

| | **Free** | **Team** | **Enterprise** |
|---|---|---|---|
| Who | Any individual, no account | Small teams, self-serve | Companies with a governance requirement |
| Tenancy | Shared, anonymous | **Dedicated** | **Dedicated** |
| Storage | Ephemeral, E2EE, 5-day default | Permanent, org-held keys | Permanent, org-held keys |
| Auth | None | WorkOS AuthKit — social, passkeys, Magic Auth | + SAML SSO / SCIM via self-hosted Ory Polis |
| Governance | — | Admin view, revoke | + audit export, retention, legal hold, residency |
| Onboarding | None | Self-serve | Human — security review, DPA, procurement |
| Marginal cost | ~$0 | **~$60/yr** | **~$60/yr + GTM time** |
| Price | $0 | Per seat | Annual contract |

**The gate between Team and Enterprise is SSO** — precisely the feature that requires procurement, a security review, and a per-connection vendor bill. Clean line, and it maps to a real marginal cost rather than an arbitrary feature fence.

**Team ships before Enterprise.** SSO is not on the critical path to first revenue. This pulls money forward and validates the buyer without a three-month enterprise sales cycle.

### What the numbers actually rest on

Two derivations, both unvalidated until buyer conversations happen. Any figure below is a starting hypothesis, not a researched price.

**Team — cost floor:** ~$60/tenant/year infrastructure, plus automated fleet operations. Genuinely cheap to serve; price it like ordinary per-seat SaaS.

**Enterprise — cost floor:** infrastructure is still negligible. The floor is set by:
- **Identity is now a flat cost, not a per-tenant one.** Self-hosted Ory Polis (Apache 2.0) provides SAML SSO and SCIM for the price of one Railway service and a database — roughly $20–40/month total, regardless of tenant count. WorkOS would have charged $125/connection/month for each, about $3,000/year per enterprise tenant, scaling linearly. Break-even is the first enterprise customer.
- **The remaining cost is go-to-market time** — sales, security questionnaires, pen-test reports, DPAs, renewals. It is the dominant cost and it is not engineering support.
- **The trade:** self-hosting the auth path means community-cadence CVE patching, since Ory gates security SLAs behind its enterprise license. Accepted deliberately; Ory Network's managed Polis is the escape hatch and the integration is identical.

**Enterprise budget anchor:** this comes out of a per-seat dev-tools line. 50 engineers × $25–50/seat/month ≈ $15–30k/year. That the cost floor and the budget anchor point at the same order of magnitude is weak corroboration that a five-figure annual contract is sane — nothing more. The real number comes from the first three buyer conversations.

## 7. Go to market

With no users and no validated buyer, both sides of the funnel start at zero. The sequencing matters more than the tactics.

1. **Design partner first, before phase 2.** One org, early, discounted or free, in exchange for being the indexing testbed and the source of the first real price signal. This is the cheapest de-risking available for the six-month infrastructure stretch, and it substitutes for the organic pull that does not exist yet.
2. **Build distribution while building the product.** The CLI, MCP server and agent skill exist and work — but publishing them is not distributing them. Getting the free tier in front of engineers is its own workstream, not a side effect of shipping.
3. **Team tier as the revenue and validation step.** Self-serve, no sales cycle. If small teams will not pay for permanent governed storage, the enterprise thesis is in trouble and it is much cheaper to learn that here.
4. **Land Enterprise on governance, renew on retrieval.** The first contract closes on audit and retention. Renewal depends on phase 5 actually working.

## 8. Success metrics

**Leading — is the wedge real?**
- Free-tier deploys per week (distribution, which currently reads zero).
- Orgs reaching first artifact deployed under an org token (activation).
- Weekly-deploying agents per tenant (retention proxy — measures the agent, not the human).
- Percentage of artifacts arriving with complete provenance (data-quality gate for phase 5).

**Lagging — is it a business?**
- Team accounts converting to paid; Enterprise deals closed.
- Security reviews passed without an exception.
- Net revenue retention.

**The one that tests the thesis**
- **Retrieval queries per active tenant per week, once phase 5 ships.** If artifacts are stored and never queried, this is a compliance product with a long infrastructure bill, not an org memory. This metric decides which.

## 9. Risks

| Risk | Why it matters | Mitigation |
|---|---|---|
| **No users, no validated buyer** | Every assumption below is untested | Design partner before phase 2; do not build SSO on speculation |
| Distribution is a workstream, not a side effect | Built ≠ adopted | Treat free-tier reach as an explicit goal with its own metric |
| Six months of undifferentiated infra before the novel part | Longest stretch with no new value shipped | Team tier ships first; prove indexing crudely on the design partner |
| Labs ship org-level artifact governance themselves | Removes the wedge | Cross-agent coverage is defensible — no lab stores a competitor's agent's output |
| Self-hosted auth on community CVE cadence | Auth outage or vuln in a governance product is existential | Pin versions, track Ory advisories, upgrade runbook; managed Polis as escape hatch |
| Brand contradiction | "A precision tool — not a SaaS platform" vs. a provisioned appliance | Separate surfaces: artfct.dev stays the funnel, enterprise gets its own |
| Vectorize economics don't reconcile | Sits under the thesis feature | Model before committing; alternative vector stores exist |

## 10. Decisions and remaining questions

**Settled**

- **A design partner will be recruited.** Confirmed as the first GTM step, ahead of phase 2.
- **Self-host Polis from day one**, including pre-revenue for testing. Running it before a customer depends on it validates the integration early and starts the operational learning curve while the stakes are low. Managed Ory Network stays the escape hatch.
- **The free tier does not change.** Anonymous, ephemeral, E2EE, no accounts. Consequence to accept: free-tier usage is countable but **not attributable** — deploy volume is measurable, individual users are not, so the funnel gives no lead list. GTM leans on the design partner and outbound instead.
- **Tenant-branded domains before non-HTML artifact types.** See the cost warning below.

**Still open**

- **Team tier pricing model, not just the number.** Per-seat looked obvious when the user was an engineer. With business users in scope, finance balks at buying seats for people who deploy twice a month — so active-user or artifact-volume pricing may fit better. Answer comes from the design partner and the first self-serve signups, not from a spreadsheet.

### ⚠️ Tenant-branded domains carry a Cloudflare plan cost

Prioritising branded domains needs a distinction that changes the price by an order of magnitude:

- **Branded console only** — `artfct.acme.com` pointing at the Laravel app. A single custom hostname. Cloudflare for SaaS includes **100 hostnames on Free, Pro and Business**, then $0.10 each. Effectively free.
- **Branded artifact origins** — `<id>.artifacts.acme.com`, which per-artifact isolation requires. That is a **wildcard** custom hostname, and **wildcard custom hostnames are Cloudflare Enterprise-plan only.**

Ship the branded console first. It satisfies most of what buyers actually mean by "our domain," costs nothing, and defers the CF Enterprise conversation until a customer is paying enough to justify it. Do not price branded artifact origins as a cheap add-on.
