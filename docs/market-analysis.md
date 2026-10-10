# Market analysis — artfct for Teams

September 2026. Companion to [architecture-decisions.md](architecture-decisions.md) and [prd.md](prd.md).

**Headline: the build order is inverted relative to where the differentiation is.** The specs put governance in phases 1–4 — the half of the problem that Anthropic, OpenAI, Vercel and Netlify have all shipped into during 2026 — and put retrieval in phase 5, which nobody occupies. That is roughly twelve months of building into the contested half before touching the uncontested one.

Nothing below invalidates an architecture decision. It changes what to build first and what to say about it.

---

## 1. The premise has partly expired

The PRD's problem statement claims agent artifacts have "no revocation, no org-level record, no retention control." **As of 2026 that is no longer true within a single vendor.**

- **Claude Code Artifacts** are in beta on Team and Enterprise plans, [an admin must enable them in organisation settings, and the owner must grant permission to share publicly](https://venturebeat.com/data/anthropics-claude-code-artifacts-update-brings-live-shared-dashboards-and-interactive-workspaces-to-enterprises).
- **OpenAI Canvas Sites** rolled out to Business and Enterprise workspaces with [admin-managed RBAC and per-site access modes](https://intuitionlabs.ai/articles/enterprise-ai-dashboards-chatgpt-claude) — self-only, whole-workspace, or custom user groups.

The governance gap was closing while this was being designed. Anyone pitching "the labs give you no controls" in front of a platform lead who has already configured those controls loses the room in the first two minutes.

**What the labs structurally cannot do is the cross-agent case.** No lab will store artifacts produced by a competitor's agent. That half of the thesis is not weakened by this — it is the only half left, and it happens to be the half with the best supporting evidence.

## 2. The strongest data point supports the surviving half

| Finding | Source |
|---|---|
| **Most engineers juggle two to four AI tools at once** | [Pragmatic Engineer, 2026](https://newsletter.pragmaticengineer.com/p/ai-tooling-2026) |
| 90% of professional developers use AI coding agents at work weekly; 68% daily | [JetBrains, Aug 2026](https://blog.jetbrains.com/research/2026/08/ai-coding-agent-adoption-2026/) |
| Claude Code at ~39% of professional developers at work, up from 18% in Jan 2026 | JetBrains |
| Copilot down from 29% to 21% year on year; large enterprises still default to it | JetBrains |

Multi-tool is the norm, not the exception, and no single vendor's console spans it. That is the premise the labs cannot serve — and it is sourced, recent, and directly on point.

The behaviour exists at scale. **Whether a budget line exists for governing it does not follow**, and no amount of desk research settles that. The design partner is the instrument that tests it.

## 3. Competitive map

### Free tier — a red ocean with no moat

[Tiiny Host](https://tiiny.host/host-html-file/) ($18/mo for custom domain and password protection), Static.app, Netlify Drop, GitHub Pages, Cloudflare Pages, TiniDrop. **OneClickLive is explicitly positioned at the AI-output workflow** — "copy the code from your AI tool, paste it, click deploy, get a shareable URL," no account required ([comparison](https://hummingdeck.com/blog/host-single-html-file)).

That is the free tier's exact pitch, already shipped by someone else. Which is fine: the free tier was kept as a funnel, not a business. But it should not be mistaken for differentiation, and expecting organic pull from it against this field is optimistic.

### Mid-market — occupied by well-funded incumbents

[Vercel](https://vercel.com/docs/deployment-protection) offers protected preview deployments on all plans, password protection via a $150/mo Advanced Deployment Protection add-on, and SAML SSO, directory sync, audit logs and 99.99% SLAs on Enterprise. Netlify matches with password-protected previews on Pro and SSO-protected previews plus SCIM on Enterprise.

A buyer who wants "share a build behind access control" already has a vendor, and that vendor has an SLA and a sales team.

### Enterprise "artifact governance" — a name collision that costs you the meeting

To a platform engineering lead, **"artifact store" means [JFrog Artifactory](https://jfrog.com/blog/what-is-artifactory-jfrog/) or [Harness](https://www.harness.io/products/artifact-registry)** — binaries, packages, SBOMs, supply-chain provenance across 60+ formats.

Walking into that conversation with that phrase invites a comparison against a job artfct is not doing and cannot win. This is a positioning fix with near-zero cost: **stop calling it an artifact store.** Call it what it is — a record of what your agents produced.

### The thesis space — thin, but not empty

One signal worth a dedicated look rather than a conclusion: **Agentage Memory**, described as a cross-vendor shared memory layer exposed as a remote MCP server that Claude, Cursor and ChatGPT read and write ([reference](https://github.com/ARUNAGIRINATHAN-K/awesome-ai-agents-2026)). Single mention, unsized, unfunded as far as this research shows. Not evidence the space is taken — evidence the positioning is being probed.

The relevant structural point: **open cross-vendor primitives now exist** — MCP, Agent Skills, plugins. That is what makes a cross-agent product buildable at all, and it cuts both ways: it lowers the barrier for everyone.

## 4. Where artfct is actually differentiated

**cross-agent × durable work product × two audiences × accumulating**

Four properties, and the combination is unoccupied:

- **Cross-vendor** — structurally impossible for a lab, not merely unbuilt.
- **Durable work product, not context.** We move finished artifacts, so the competitive set is *not* the agent-memory cluster (mem0, Letta, Zep, Agentage). A markdown memory note is not a substitute for a working dashboard.
- **Two audiences, one object.** A teammate opens it from Slack; an agent finds it through retrieval. Memory products serve agents only; Vercel and Tiiny Host serve humans only.
- **It accumulates.** Usage signal and collections turn a pile of outputs into the way a team works — which is also the switching cost.

And a segment the first draft of the PRD missed entirely: **business users.** A PM generating a report in Cursor has nowhere to put it, and `.impeccable.md` had already named that audience before the PRD narrowed it. Value runs inverse to technical ability.

- Labs: single-vendor by construction. Will not index a competitor's output.
- Vercel/Netlify: deployment platforms. No provenance, no agent retrieval, no reason to build it.
- JFrog/Harness: build artifacts, not agent output. Different corpus, different buyer moment.
- HTML hosts: no governance, no persistence guarantee, no index.
- Memory layers: context, not deliverables. Serve agents, not teammates.
- Confluence / Google Docs: documents, not interactive HTML an agent produced — and this is the boundary to hold. Expanding the *user* to business teams must not expand the *artifact type*, or we become a worse wiki.

Nobody occupies that intersection. It is narrow, and it is real.

The uncomfortable part: **that intersection is spec 12 and spec 13 — the last two before commercialisation.** Everything ahead of them competes with someone better funded.

## 5. What this changes

**Rewrite the PRD's problem statement.** The "no revocation / no org record / no retention" bullets are now false as stated and will be corrected in the room. Replace with the cross-agent framing, which the multi-tool data supports directly.

**Stop saying "artifact store."** Say "a record of what your agents built." Avoids the Artifactory comparison entirely.

**Reprice expectations for the free tier.** It is a funnel in a commoditised field against at least one competitor aimed at the same AI-output workflow. Keep it — decision 08 was made deliberately — but do not model organic pull from it.

**Consider what the design partner is actually for.** Currently framed as de-risking phase 5. On this analysis it is more than that: it is the only way to learn whether cross-agent artifact governance is a budget line at all, before twelve months of infrastructure is spent on the assumption that it is.

## 6. The decision this forces

**If the differentiation is in Track F, the spec sequence disagrees with the strategy.**

Tracks C, D and E — control plane, tenancy, enterprise governance — are eleven of the fifteen specs, and they build toward the contested half. Track F is where nobody else is.

Three options, and this is a scope call rather than a technical one:

1. **Keep the order.** Governance first is what makes an enterprise sale legible, and retrieval is worthless without a corpus to retrieve from. Accepts twelve months before differentiation.
2. **Pull Track F forward, crudely.** After spec 9 there is a corpus and a tenant. Prove retrieval on the design partner before building the enterprise governance surface. Risks selling something with no audit trail.
3. **Re-cut the pitch, keep the build.** Lead every conversation with org memory, ship governance first because it is the substrate. The honest version of this is that you are selling the roadmap, which some buyers accept and procurement usually does not.

No recommendation offered here — the trade depends on whether the first design partner is bought by governance or by retrieval, and that is knowable only by asking them.

---

## Sources

- [JetBrains — AI Coding Agent Adoption 2026](https://blog.jetbrains.com/research/2026/08/ai-coding-agent-adoption-2026/)
- [The Pragmatic Engineer — AI Tooling for Software Engineers in 2026](https://newsletter.pragmaticengineer.com/p/ai-tooling-2026)
- [VentureBeat — Claude Code Artifacts for enterprises](https://venturebeat.com/data/anthropics-claude-code-artifacts-update-brings-live-shared-dashboards-and-interactive-workspaces-to-enterprises)
- [IntuitionLabs — Enterprise AI dashboards: ChatGPT and Claude usage controls](https://intuitionlabs.ai/articles/enterprise-ai-dashboards-chatgpt-claude)
- [Vercel — Deployment Protection](https://vercel.com/docs/deployment-protection)
- [Hummingdeck — Host a single HTML file: 8 tools compared](https://hummingdeck.com/blog/host-single-html-file)
- [Tiiny Host — Host HTML file](https://tiiny.host/host-html-file/)
- [JFrog — What is Artifactory](https://jfrog.com/blog/what-is-artifactory-jfrog/)
- [Harness — AI-Powered Universal Artifact Registry](https://www.harness.io/products/artifact-registry)
- [awesome-ai-agents-2026 — cross-vendor agent tooling](https://github.com/ARUNAGIRINATHAN-K/awesome-ai-agents-2026)

Adoption figures are third-party survey data. Vendor feature and pricing claims are from vendor documentation as of September 2026 and should be re-checked before they appear in a pitch.
