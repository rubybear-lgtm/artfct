# artfct → enterprise artifact store: what it would take

Exploration only. No code, no migrations, no dependency changes.

**Status: architecture and phase-1 client work decided; ready to implement §12.** Cloudflare-native, Workers for Platforms, one codebase with two storage modes, flat hostname scheme, provenance captured from day one. See §3 for the reopen conditions and §11 for the phase-1 decision record.

**Decisions taken as given:** off KV; flexible multi-file artifacts including JS; per-customer isolated deployment; artifacts stored forever with indexing-for-agents as the future play; auth is table stakes; **all-Cloudflare, cheapest option, no BYOC until a customer asks.**

## 0. Where the codebase is today

| Piece | Reality |
|---|---|
| `backend/src/lib.rs` (804 LOC) | The entire product. Cloudflare Worker, Rust/WASM. `POST/PATCH/DELETE /v1/artifacts`, `GET /p/{id}`. |
| Storage | **KV only** (`ARTIFACTS_KV`). One key per artifact, value = `StoredArtifact` JSON. No index, no owner, no query. |
| Crypto | E2EE. Ciphertext + IV stored; key in the URL fragment, derived client-side. Server cannot read content. |
| Identity | None. Anonymous. Rate limit per-IP via Cloudflare WAF (`cloudflare-rate-limits.mjs`). |
| Laravel app | Marketing only — `Route::inertia` for `/`, `/docs`, `/blog`. `app/Models/User.php` is unused scaffolding. |
| Limits | 1 MB HTML, default TTL 5 days, max 1 year. |
| Distribution | CLI + `deploy_to_canvas` MCP tool + agent skill, already installed into Cursor/Claude/Codex/Gemini. |

The distribution surface is the asset. The storage layer isn't — and the decisions replace it.

## 1. Staying on Cloudflare is the right call, and it changes the build shape

An earlier draft of this analysis assumed multi-cloud portability and concluded you'd need to rewrite the backend as a portable container stack. **That's off the table now, and the CF-native path is materially smaller.** Cloudflare has a first-party primitive for nearly every piece:

| Need | CF primitive | Confidence |
|---|---|---|
| Per-tenant isolated deployment | **Workers for Platforms** — dispatch namespaces | Verified in docs |
| Per-artifact isolated origin | **Cloudflare for SaaS** custom hostnames + `*/*` wildcard route + KV routing | Verified in docs |
| Metadata | D1 (SQLite), one DB per tenant | Verified — 10 GB/DB on paid, docs explicitly suggest per-tenant DBs |
| Bundle bytes | R2 — **zero egress**, which is the actual reason CF is cheapest for serving artifacts | High |
| Job runner | Queues + Cron Triggers | Expected native, confirm at build |
| Headless render for indexing | Browser Rendering | Expected native, confirm at build |
| Embeddings + vector search | Workers AI + Vectorize | Expected native, confirm at build |
| Fleet provisioning | Terraform/Pulumi CF providers + WfP API | Expected native, confirm at build |

**So: extend the existing Rust Worker, don't rewrite it.** That's the single biggest change from the previous draft.

*Worth evaluating separately:* Cloudflare **Artifacts** (versioned file trees with Git-compatible remotes) maps suspiciously well onto "multi-file bundle, stored forever, versioned." Docs are draft-stage — treat as a spike, not a plan.

## 2. Constraints the decisions settle (not options)

**E2EE is over for enterprise.** You cannot index ciphertext. Enterprise artifacts are server-readable with org-held keys; the anonymous free tier keeps E2EE. Market the enterprise side as *ownership* — the org holds the key, not the vendor, not Anthropic. The two tiers now make different promises; say so plainly rather than letting one page imply both.

**Auth is phase 1.** SSO/SAML + SCIM up front. One mercy: per-tenant deployment makes this *easier* than multi-tenant SSO — one IdP per deployment configured at provision time, no email-domain tenant discovery, no cross-tenant token confusion. Buy it (WorkOS/Stytch); it isn't your differentiator.

**"Forever" changes storage economics and the ID scheme.** No TTL default; deletion becomes explicit policy (retention windows, legal hold, soft-delete, proof of erasure) instead of today's collect-by-default. And 10-char random IDs with a read-back collision check don't survive an unbounded corpus.

**Settled: one codebase, two storage modes.** A single Rust Worker with KV + E2EE + ephemeral for the free tier and D1/R2 + plaintext + permanent for enterprise. See §11. **What is not open: one CLI and one `deploy_to_canvas` MCP tool must point at either.** That client is the moat — it's already installed in engineers' agents.

## 3. The deployment/isolation fork — name it, don't pick it silently

**First, a correction to the framing.** "A pod per customer" isn't a tenancy model, it's a scheduling detail. Pods are ephemeral and fungible — they get rescheduled, evicted, and replaced. The tenancy boundary is **namespace + own database + own bucket**, with pods as an implementation artifact underneath. Pod-per-customer taken literally is also the worst cost profile available: an idle pod per tenant, billed 24/7, against a Workers model that costs nothing at rest.

Four readings of "their own deployment":

| Model | Isolation claim | Ops cost | Cost at rest |
|---|---|---|---|
| Separate CF account per tenant | Strongest | Worst — separate billing, tokens, limits, no cross-account tooling | ~zero |
| **Dispatch-namespace script per tenant** (Workers for Platforms) | Strong: untrusted mode, per-tenant D1/R2 bindings, per-dispatch CPU + subrequest limits, tag-based fleet ops | Moderate — **recommended now** | ~zero |
| One Worker, tenant-routed, separate D1 + R2 | Weakest — "we check a tenant id in code" | Cheapest | ~zero |
| **k8s namespace per tenant** (Postgres + bucket per tenant) | Comparable to WfP on paper; **better in a sales call** | Highest — nodes, upgrades, ingress, cert-manager, autoscaling, on-call | Pays 24/7 per tenant |

**The honest concession on k8s:** namespace + network policy + separate DB is not obviously *stronger* isolation than a WfP untrusted isolate with its own bindings — neither is a VM, both share a kernel or a process. But k8s **sells better to security reviewers**, because it's a known quantity, while Workers for Platforms requires explaining Cloudflare's isolate model to someone who has never heard of it. That's a real advantage, it's the argument a prospect will make, and it's worth pre-empting rather than dismissing.

**Decision: Cloudflare-native, dispatch-namespace script per tenant.** Recorded, not provisional. Rationale: ~10× lower infra cost with no crossover point (§10), no backend rewrite, and CF has a first-party primitive for every phase (§1).

**BYOC and self-hosting are out of scope, not deferred.** k8s would be the answer if they were on the table; they aren't. That removes the timing bet entirely.

**Conditions that reopen this decision:**
- A security review rejects the Workers isolate model outright (see the concession above — plausible, and the argument a prospect will make).
- Phase 5 indexing volume makes Browser Rendering or Vectorize uneconomic against a node pool you control.

**Costs that survive or don't:**
- **R2 zero egress is the dominant cost lever**, and it survives a move. Serving HTML bundles to browsers is nearly pure egress; on EKS/GKE you'd pay per-GB on every artifact view. Moving compute doesn't require moving bytes.
- So the sensible version of k8s isn't all-or-nothing: **compute on k8s, bytes still in R2, Cloudflare still in front** for wildcard hostnames and certs. That keeps the cost structure and preserves the CF-for-SaaS origin-isolation work in §4.
- k8s also removes the Workers CPU/memory ceilings and D1's 10 GB-per-DB constraint (plain Postgres instead), which matters more as bundles get larger.

**Where k8s wins on merits, not familiarity: phase 5.** Headless render pools and embedding jobs are long-running and CPU-heavy — the worst fit for Workers' execution model, the best fit for a node pool you control. Expect *some* nodes eventually even if the serving path stays on Cloudflare. This isn't binary.

**Cloudflare Containers is not the compromise it looks like.** Per its docs: beta, no SLA, 2–3s cold starts, no autoscaling (manual load balancing), rolling deploys, ephemeral disk. That combination disqualifies it as the per-tenant serving path for a product carrying an uptime commitment.

**The export path survives this, with a different justification.** It was scoped as the hedge that would make a future k8s build a port rather than a rewrite; that reason is gone. It stays because a governance buyer asks "can we get our data out?" at procurement, and answering it well is part of the ownership pitch we sell against the labs. Open formats in R2 plus a working export, in phase 1 — now a product requirement, not an architectural hedge.

## 3b. Trust boundary: Laravel is the control plane, the Worker is the data plane

Previously flagged as an open fork. **Decided — and WorkOS is what decides it.**

Laravel ships an official **WorkOS AuthKit** variant of the React starter kit, on exactly this stack (Inertia 3, React 19, Tailwind 4): SSO, social auth, passkeys, Magic Auth, configured with three env vars. The same starter kits ship **Teams** natively — users belong to one or more teams, personal team on registration, invitations, switching, team management screens, team-scoped routes, and reserved-slug collision handling (which matters, since §11 makes tenant slugs load-bearing on DNS).

So the org, membership, invitation and identity model is **inherited, not built**.

- **Laravel** owns identity, orgs, memberships, roles, tokens, the admin console, and tenant provisioning.
- **The Worker** owns artifact create, resolve and serve.
- **The seam:** Laravel mints short-lived JWTs; the Worker verifies them at the edge. No origin round-trip on the hot path. Revocation via short TTL plus a KV denylist.

The two rejected alternatives: a Worker→Laravel callback per request (correct revocation, but puts the origin in the hot path and removes the reason to be on the edge), and moving `/v1/*` into Laravel entirely (simplest model, best for a future self-host story, worst for global write latency — revisit only if BYOC lands).

**Consequence:** phase 2 shrinks substantially. It was scoped at ~4 weeks assuming identity got wired by hand; most of that is now starter-kit configuration plus the JWT seam.

### Identity splits across two systems, on cost grounds

**WorkOS AuthKit for Free and Team; self-hosted Ory Polis for Enterprise SSO and SCIM.**

WorkOS charges $125/connection/month for SSO and again for Directory Sync — roughly $3,000/year per enterprise tenant, scaling linearly. Polis (Apache 2.0, formerly BoxyHQ SAML Jackson) does SAML/OIDC SSO *and* SCIM for a flat hosting cost that does not scale with tenants. Break-even is the first enterprise customer.

What each covers:

| Tier | Identity | Cost |
|---|---|---|
| Free | none (anonymous) | $0 |
| Team | WorkOS AuthKit — social, passkeys, Magic Auth | $0 to 1M MAU |
| Enterprise | Ory Polis — SAML/OIDC SSO + SCIM directory sync | flat hosting only |

**Why this works cleanly:** Polis abstracts SAML into a **standard OAuth 2.0 authorization code flow**, and AuthKit is OAuth too. So both are the same integration shape in Laravel — a Socialite provider each, normalising into one resolve-or-create-user action. Route by an `auth_mode` column on the org (`authkit` | `polis`); Laravel picks the driver. Polis keys connections by `tenant` + `product`, which maps directly onto org id + `artfct`.

**Deployment:** Docker `boxyhq/jackson`, port 5225, Postgres or Neon, `/api/health` for healthchecks. The Laravel app already runs on Railway, so this is a second Railway service and a database — extending an existing stateful surface rather than creating one.

**The accepted risk, stated plainly:** the Ory Enterprise License gates CVE patching with SLAs and "advanced scaling and multi-tenancy features." Self-hosting the auth path on community patch cadence is a real exposure for a product whose pitch is governance, and it will come up in a security review. Mitigations: pin versions, subscribe to Ory security advisories, keep an upgrade runbook, and treat Ory Network's managed Polis as the escape hatch — the integration is identical, so switching is a config change, not a rewrite.

**Also note:** Polis is SSO and SCIM only — no user management, no social login, no passwords. That is exactly why AuthKit stays for Free and Team rather than being replaced.

## 4. What the decisions force that isn't obvious

**Per-artifact origin isolation is a phase-1 requirement.** The sharpest one. Today's CSP allows `unsafe-inline`, `unsafe-eval` and any `https:` origin — fine when artifacts are anonymous, ephemeral and encrypted. The moment artifacts contain JS, are org-private, and sit behind ambient session auth on a shared origin, **JS in artifact A can read artifact B**. You need one origin per artifact: `<id>.artifacts.acme.artfct.dev`, cookieless, with short-lived signed access tokens. Cloudflare for SaaS + a `*/*` wildcard route + hostname→tenant lookup in KV is the documented pattern, and custom hostname `custom_metadata` can carry the routing target. Native, but it's a schema-and-DNS decision — retrofitting it invalidates every URL you've issued.

**"Flexible artifacts" breaks the API contract.** A multi-file bundle with JS, CSS and assets isn't `body_ciphertext_b64` + `body_iv_b64`. It's a manifest plus file uploads, with an entrypoint, content types, and per-file integrity. `/v1/` needs versioning and the CLI/MCP tool must speak both dialects. That seam is where compatibility pain lives; design it once.

**Content-addressed IDs.** Hash the bundle. Solves collisions at unbounded scale, gives dedupe free (agents redeploy near-identical artifacts hundreds of times), and makes immutability plus versioning natural — an artifact becomes a pointer to a series of content hashes.

**Capture provenance now, even if nothing reads it.** Which agent, which model, which session, which repo and branch, which prompt hash. Indexing-for-agents is only valuable with that context and **you cannot backfill it** — the sessions are gone. Few columns today, impossible in a year.

**Indexing JS-heavy artifacts needs a headless render pass.** A React dashboard's source HTML may contain almost no indexable text; you need post-hydration extraction via Browser Rendering, on a queue, not in the request path. Plan the job runner in phase 1 even if indexing ships much later.

**Fleet upgrades get cheaper but not free.** Versioned deploys across a dispatch namespace in one account beat upgrading N container stacks, and WfP tag-based operations help. But **per-tenant D1 means running N schema migrations**, with partial-failure and version-skew handling. That's the recurring operational cost, and it sets your price floor.

## 5. The lock-in trade — name it before a customer does

You'd be selling "own your artifacts, don't get locked into a lab's ecosystem" from a stack that is 100% Cloudflare, leaning hardest on its least-portable primitives (dispatch namespaces, D1, Vectorize). The irony will land in a sales call eventually.

It's still the right trade — cheapest, fastest, and BYOC has no buyer yet. "Not until asked" is *not yet*, not never, so take the cheap hedge now: **bundles as open formats in R2, plus a working export path** (artifacts + metadata + audit log, out, in a documented format). That's most of the honest answer to the ownership question and costs almost nothing if designed in from the start. Note in your own planning that the WfP/D1/Vectorize dependencies are the expensive ones to unwind later.

## 6. Dependency order

1. **Storage split + tenancy model.** D1 per tenant for metadata (`orgs`, `users`, `memberships`, `api_tokens`, `artifacts`, `artifact_versions`, `files`, `shares`, `audit_events`, `provenance`), R2 for content-addressed bundles. Decide one-codebase-or-two here. Lifts the 1 MB cap immediately — enterprise dashboards with embedded data blow through it on day one. Old KV links keep resolving from the free-tier path; nothing needs migrating.
2. **Auth.** SSO/SAML + SCIM, per-tenant IdP config at provision time. Org-scoped API tokens for CLI/MCP in the same model. admin / member / viewer is enough to start.
3. **Bundle API v2 + per-artifact origin isolation.** Manifests, wildcard hostnames, signed access tokens, dual-dialect CLI/MCP. These ship together — the URL scheme depends on both.
4. **Provisioning + fleet.** WfP dispatch namespace, Terraform/Pulumi modules, per-tenant secrets and keys, versioned releases, D1 migration runner, per-tenant observability (Logpush/Tail Workers on the dispatch Worker, Analytics Engine for usage). Plus audit export to SIEM, retention policy, legal hold.
5. **Indexing.** Browser Rendering → text extraction → Workers AI embeddings → per-tenant Vectorize index → an MCP retrieval tool. **This is the actual product**: the org's memory of what its agents built, queryable by the next agent. Everything before it is substrate.
6. **Billing, quotas, admin console.** Boring, last.

## 7. Rough effort shape

| Phase | Scope | Feel |
|---|---|---|
| 1 | D1 + R2 split, content-addressed bundles, tenancy model | ~6–8 weeks |
| 2 | SSO/SAML/SCIM (bought), org tokens, roles | ~4 weeks |
| 3 | Bundle API v2, per-artifact origin isolation, CLI/MCP dual dialect | ~6–8 weeks |
| 4 | WfP provisioning, IaC, D1 migration runner, audit/retention | ~8 weeks, then permanent ongoing cost |
| 5 | Indexing: Browser Rendering, embeddings, Vectorize, retrieval MCP tool | ~8 weeks |
| 6 | Billing, quotas, console | ~3 weeks |

Call it **6–9 months** to a credible enterprise product — roughly half the portable-stack estimate, entirely because of the Cloudflare decision. Phase 4 never really ends.

## 8. What breaks that isn't on the list

- **The brand.** `.impeccable.md` says "a precision tool — not a SaaS platform," and "no tier selection on the landing page — this simplicity is the feature." A provisioned per-customer deployment is about as far from that as it gets. Resolve it structurally: artfct.dev stays exactly as it is and stays the funnel; enterprise gets its own surface.
- **Forever is an unbounded cost curve** against a per-seat price. R2 lifecycle tiering and per-tenant quotas need to exist before the first large customer.
- **Deletion under dedupe.** GDPR erasure still applies, and content-addressed dedupe makes "delete this one thing" harder. Plan reference counting.
- **Data residency.** Per-tenant deployment is your best answer — confirm CF's jurisdiction restrictions cover the regions you'll be asked for.
- **SLA and support.** A permanent store people put work artifacts in is an uptime commitment across N deployments. On-call, not a status page.

## 9. The strategic read

What you're describing isn't an artifact host. It's **an organization's memory of what its agents produced** — permanent, governed, indexed, fed back to the next agent. The hosting is substrate. That's far more defensible than link-sharing, and the CF decision makes the substrate cheap enough to get there.

Two things to act on before phase 1:

1. **Provenance columns cost nothing today and cannot be backfilled.** Put them in whatever schema you write first.
2. **Preserve the one-client invariant.** Every architectural choice should keep a single CLI and MCP tool pointing at either stack with identical ergonomics.

The residual risk is ~6 months of undifferentiated infrastructure before the novel part. If you can prove the indexing thesis earlier — crudely, on one design-partner tenant — that de-risks the whole sequence.

## 9a. Tier upgrade: Team → Enterprise

Upgrading is a **flag flip, not a migration** — a direct consequence of §11's decision that every paying tenant gets a dedicated deployment. Nothing moves: same dispatch-namespace script, same D1, same R2, same hostnames, so every artifact link already shared keeps working. Audit export, retention, legal hold, residency and sharing controls are feature gates on a `plan` column.

**The hard part is the identity switch.** Team authenticates through WorkOS AuthKit, Enterprise through Ory Polis. The same human returns with a different external identifier — AuthKit user id versus the IdP's SAML NameID. Handled naively this creates duplicate user rows and orphans people from their own artifacts.

Three failure modes to design for:

- **Email mismatch** — signed up on AuthKit with a personal address, corporate IdP asserts `@acme.com`. Matching on email alone silently orphans them.
- **People absent from the IdP** — contractors, personal accounts — locked out at cutover.
- **Admin self-lockout** — if `auth_mode` flips to Polis before the SAML connection is tested, nobody can log in to fix the SAML connection. The classic footgun.

**Two schema decisions that make this cheap, and they belong in phase 2** — same class of finding as the provenance columns, in that retrofitting them after Team users exist is exactly the painful migration this avoids:

1. **An `external_identities` table** (`user_id`, `provider`, `external_id`, `email`, `verified_at`) rather than a `workos_id` column on `users`. One human, many identities, across providers.
2. **`auth_mode` as a state machine, not a boolean** — `authkit` → `dual` → `polis`. During `dual` both providers are accepted, giving a window to test the SAML connection, link identities on first Polis login, and see who hasn't migrated before enforcement.

Plus **domain verification** before an org can enforce SAML — otherwise "enable SSO for @acme.com" is an account-takeover vector. And build the **downgrade** path at the same time; it's rare, but testing one direction only breaks someone's login the first time a contract lapses.

## 9b. Hosting split: Railway + Cloudflare

**Railway** hosts the Laravel control plane and, once the first enterprise customer arrives, Ory Polis. **Cloudflare** hosts the data plane — Workers, D1, R2, KV, and later Queues, Browser Rendering and Vectorize.

**Rejected — all-Cloudflare:** Workers has first-class support for JavaScript, TypeScript, Python and Rust plus Wasm. **PHP is not supported**, so Laravel cannot run there. The only route is Containers, which is beta, has no SLA, 2–3s cold starts, no autoscaling and rolling deploys — worse properties for a control plane owning auth and the admin console than for artifact serving, and it requires Workers Paid anyway. A migration would move the control plane onto beta infrastructure and still not reach $0.

**Rejected — Laravel Cloud:** costs the same as Railway ($5/mo base, $5 usage credit, first month free) and is a better *Laravel* host — scale-to-zero with sub-500ms wake, bundled serverless Postgres that also sleeps. But it is **PHP-only**, and Polis is a Next.js app. Adopting it means running two platforms instead of one, to save nothing.

**Reopen if** Polis moves to Ory Network's managed tier: the platform split disappears and Laravel Cloud becomes the clearly better host for what remains.

### Pre-revenue cost discipline

The lever before revenue is not the host — it is not provisioning what is not needed yet.

- **Workers for Platforms is $25/mo with no free tier.** Per-tenant isolation is not needed at zero tenants; don't provision it until the first paying customer covers it.
- Queues, Browser Rendering and Vectorize belong to phases 3–5. Free tiers exist (10 browser-min/day, 30M queried dimensions) but none of it is needed yet.
- D1 (5 GB), R2 (10 GB, 1M class A, 10M class B) and KV free tiers comfortably cover pre-revenue volume.
- **One technical trigger worth knowing: Workers Free caps CPU at 10 ms per request.** Today's worker fits; permanent-mode with D1 queries plus R2 fetches may not. Expect Workers Paid ($5/mo, 30M CPU-ms) to be forced by CPU time rather than request volume.

**Realistic pre-revenue total: ~$5/month.** A PHP app in the stack means $0 was never reachable, and the gap isn't worth a migration.

## 10. Cost approximations

All Cloudflare list prices below were pulled from developers.cloudflare.com during this analysis. AWS figures are recalled list prices — directionally right, verify before quoting.

### Model assumptions

Per tenant: 30 engineers × ~3 artifacts/workday ≈ **2,000 artifacts/month**. Average bundle **2 MB across ~8 files** (multi-file with JS is bigger than today's 1 MB single HTML). Each artifact viewed ~10 times ≈ **20,000 views/month**, ~8 file fetches each. Artifacts stored **forever**, so storage is cumulative. Dedupe from content-addressing assumed at 0% (conservative — agents redeploying near-identical artifacts should realistically save 20–40%).

### Cloudflare unit prices

| Line | Price |
|---|---|
| Workers for Platforms | $25/mo — includes 20M requests, 1,000 scripts, 60M CPU-ms; $0.02/extra script |
| Workers Paid | $5/mo base; 10M req + 30M CPU-ms included |
| R2 storage | $0.015/GB-mo (Standard), 10 GB free; **egress free** |
| R2 ops | Class A $4.50/M (1M free), Class B $0.36/M (10M free) |
| D1 | $0.75/GB-mo over 5 GB; 25B rows read + 50M rows written/mo included; **no per-database fee** |
| Browser Rendering | 10 browser-hrs/mo included, then $0.09/hr; 10 concurrent included, then $2/browser |
| Queues | $0.40/M ops after 1M free |
| Vectorize | dimension-based — see the warning below |

### 10 tenants, month 12

| Line | Volume | Cost/mo |
|---|---|---|
| Workers for Platforms | 10 scripts, 1.6M requests | **$25** |
| R2 storage | 40 GB/mo added × 12 = 480 GB | **$7** |
| R2 Class A (writes) | 160k ops | $0 (free tier) |
| R2 Class B (reads) | 1.6M ops | $0 (free tier) |
| R2 egress | ~400 GB served | **$0** |
| D1 | 10 DBs, <1 GB, ~1M rows read | $0 (free tier) |
| Browser Rendering (indexing) | 20k renders × ~5s = 28 hrs | **$2** |
| Workers AI embeddings | ~400k chunks | ~$5 (rough) |
| Queues | ~100k ops | $0 |
| **Total** | | **≈ $40–60/month** |

### 100 tenants, month 24

| Line | Volume | Cost/mo |
|---|---|---|
| Workers for Platforms | 100 scripts, 16M requests | **$25** |
| R2 storage | 400 GB/mo × 24 = ~9.6 TB | **$145** |
| R2 Class B | 16M ops | **$2** |
| R2 egress | ~4 TB served | **$0** |
| D1 | 100 DBs | ~$0–20 |
| Browser Rendering | ~280 hrs | **$25** |
| Workers AI + Queues | | ~$50 |
| **Total** | | **≈ $250–400/month** |

**The headline: infrastructure is a rounding error against any enterprise price point.** At 100 tenants you're under $5/tenant/month against a product you'd price in the hundreds or thousands. Your costs are engineering time and support, not compute.

**"Forever" is genuinely cheap on R2.** 100 tenants at year 5 ≈ 24 TB ≈ $360/mo. Permanent retention is not the cost bomb it sounds like — at these bundle sizes.

### Cost sensitivities, in order

1. **Bundle size.** Everything scales off it linearly. If real bundles average 20 MB (embedded datasets, images, model weights in a demo) rather than 2 MB, storage goes to ~$1,450/mo at 100 tenants. **Set a per-tenant quota and a per-artifact size cap from day one** — this is the only line that can run away.
2. **Vectorize — the one number I can't stand behind.** Billing is on "queried vector dimensions," which the docs define as (vectors in index + query vectors) × dimensions, *per query*. A naive reading of that formula against a 4.8M-vector corpus produces absurd numbers, yet Cloudflare's own worked example (50k vectors, 200k queries/mo) comes to $1.94/mo — the two don't reconcile. **Model this properly before committing to Vectorize for phase 5**; it's the only line item with order-of-magnitude uncertainty, and it sits under the feature that's supposed to be the actual product.
3. **Views per artifact.** R2 Class B and Worker requests are cheap, but a viral or heavily-embedded artifact changes the shape.

### The origin-isolation gotcha, with a way around it

**Wildcard custom hostnames on Cloudflare for SaaS are Enterprise-plan only.** If you'd assumed `*.artifacts.acme.com` per tenant on a Business plan, that's a hard blocker and an Enterprise-zone conversation.

But you only need Cloudflare for SaaS if tenants bring **their own** domain. Serving per-artifact origins under **your own** zone needs no CF for SaaS at all — just a wildcard DNS record. One caveat: Universal SSL covers a single wildcard level, so `*.artfct.dev` covers `abc123.artfct.dev` but **not** `abc123.artifacts.artfct.dev`. Two options:

- Flatten to one level — `<tenant>--<artifactid>.artfct.dev` — and stay free.
- Or pay **Advanced Certificate Manager (~$10/mo)** for the deeper wildcard and keep the nicer hierarchy.

Treat tenant-branded domains (`artifacts.acme.com`) as a paid enterprise add-on that triggers the CF Enterprise plan, not as a default.

### k8s comparison, same 10 tenants

| Line | Cost/mo |
|---|---|
| EKS control plane | ~$73 |
| Nodes (2–3 × m6i.large, HA minimum) | ~$150–190 |
| Load balancer | ~$20 |
| Postgres (10 × db.t4g.micro, or shared cluster) | ~$120 |
| **Egress — 400 GB × $0.09/GB** | **~$36, and it grows linearly forever** |
| **Infra subtotal** | **~$400–450/mo** |
| Cluster ops labor (0.25–0.5 FTE) | **$40–80k/yr** |

**Roughly 10× the Cloudflare infra cost at small scale, before the labor line — which is the one that actually matters.** And the gap widens with traffic rather than closing: R2's free egress means the serving path never crosses over. At 100 tenants, AWS egress alone is ~$360/mo against $0 on R2.

There is no volume at which k8s becomes cheaper for *serving*. If you move to k8s it will be for BYOC or for phase-5 compute, not for cost — the hybrid in §3 (compute on nodes, bytes still in R2) is what keeps that from being expensive.

## 11. Phase-1 decision record

### One codebase, two storage modes

A single Rust Worker. The storage backend is an interface with two implementations:

| Mode | Backend | Crypto | Lifetime | Identity |
|---|---|---|---|---|
| `ephemeral` (free tier, today's behavior) | KV | E2EE, key in fragment | TTL, default 5 days | anonymous |
| `permanent` (enterprise) | D1 metadata + R2 content | server-readable, org-held key | forever + retention policy | org-scoped auth |

**What this buys:** one CLI, one MCP tool, one preview shell, one CSP policy to reason about, no drift between the tiers. Today's `/p/{id}` path keeps working untouched.

**Where it will hurt, so design for it:** the two modes diverge on nearly every axis — the abstraction has to sit at the *artifact* level (`resolve`, `store`, `delete`, `list`), not at the storage-primitive level, or you end up with a lowest-common-denominator interface that fits neither. Expect mode-specific code paths in the handlers and keep them explicit rather than hiding them behind a leaky trait. And the free tier is the higher-traffic, lower-value path: don't let enterprise complexity slow it down or expand its attack surface.

### Flat hostname scheme

`<tenant>--<artifact-id>.artfct.dev` — one wildcard level, covered by Universal SSL, no Advanced Certificate Manager, no Cloudflare for SaaS, $0.

**Constraints this imposes on IDs and slugs, which are now load-bearing:**
- A DNS label is **63 characters max**. Content-addressed IDs are hashes — truncate to a fixed prefix (32 hex chars is ample for collision resistance at this corpus size) and cap tenant slugs at ~24 chars. 24 + 2 + 32 = 58, inside the limit with headroom.
- **Reserve `--` as the separator and disallow it in tenant slugs**, or `acme--corp--abc123` is ambiguous. Enforce at tenant creation, not at parse time.
- Labels are case-insensitive and restricted to `[a-z0-9-]`, and can't start or end with `-`. Lowercase hex IDs and slugified tenant names satisfy this; validate on both.
- Punycode/homograph: reject non-ASCII in tenant slugs outright.

Tenant-branded domains are the next priority after the P2 thesis work, with an important split: a **branded console** (`artfct.acme.com`, one custom hostname) is effectively free — CF for SaaS includes 100 hostnames on Free/Pro/Business. **Branded artifact origins** (`<id>.artifacts.acme.com`) need a *wildcard* custom hostname, which is **Cloudflare Enterprise-plan only**. Ship the console first; don't price branded artifact origins as a cheap add-on.

### Provenance and metadata — required, not optional

Captured at create time on every enterprise artifact, indexed for later retrieval. None of this can be backfilled.

**Agent provenance:** agent name and version (`claude-code`, `cursor`, `codex`), model id, session or conversation id, tool name that produced it (`deploy_to_canvas`), prompt hash.

**Code provenance:** repo URL, branch, commit SHA, working-directory-dirty flag, file path if derived from one.

**Human provenance:** authenticated user id, org id, API token id used, client IP, CLI version.

**Artifact facts:** content hash, byte size, file count, entrypoint, declared external dependencies (which CDNs the bundle pulls from — useful for both security review and the CSP), detected framework, title and description.

**Lifecycle:** created-at, superseded-by (the version chain), view count, last-viewed-at, retention class, legal-hold flag.

Two design notes: the CLI and MCP tool have to *send* most of this. The client work **decouples from phase 1** — the clients can start collecting and sending provenance immediately while the server ignores unknown fields, and the schema consumes it when D1 lands. See §12; that refactor is shippable before any storage work — and the MCP tool is the awkward one, since an agent may not know its own session id without the host passing it. Accept partial provenance rather than blocking a deploy on it, but record which fields were absent so you can tell "unknown" from "not captured yet."

Store the flexible parts as a JSON column with a handful of promoted, indexed columns (`org_id`, `user_id`, `agent`, `repo`, `content_hash`, `created_at`). D1 is SQLite — JSON1 functions are available for querying the rest without a migration every time a client starts sending a new field.

## 12. CLI / MCP refactor plan

Scoped against the current source. This work is **shippable before phase 1**. No users, so no migrations or compatibility shims — provenance is required from the start and the request shape changes in place.

### The spine: MCP session state

`mcp.rs` is stateless per line. `handle_json_rpc` is a free async fn, and `initialize_result()` (`mcp.rs:101`) discards `request.params` entirely — which is where `clientInfo` lives. So capturing client identity is **not a field addition, it's introducing a session object**.

- `run_stdio_server` owns a `Session` for the process lifetime.
- `handle_json_rpc` takes `&mut Session` (or becomes a method on it).
- `initialize` stores `clientInfo.name` / `.version` / `.title`, plus a server-minted `session_id` (a per-process UUID — MCP stdio has no session concept, so this is the best available correlation key).
- `call_tool` reads the session when building provenance.

**Honest cost:** the existing tests `handles_initialize` and `lists_deploy_to_canvas_tool` call `handle_json_rpc` as a free fn and will need updating. That's the real churn in this change.

### `--host` flag, paired with setup

`cli.rs` `McpCommand::Serve` is a bare variant today; it needs args:

```
artfct mcp serve --host cursor
```

`setup.rs` already knows the target agent for each config it writes ("Claude Code", "Cursor", "Gemini", "Codex", "OpenCode") — it writes the flag into `args` at install time. **These two land together or the flag is dead weight.**

Resolution order at runtime: `--host` → `clientInfo.name` → parent process name → env var heuristics. Each resolution records its own `agent_source` so "cursor (from config)" is distinguishable from "cursor (guessed from parent pid)".

### Shared provenance struct

`deploy_from_cli` (`main.rs`) and `call_tool` (`mcp.rs`) both call `prepare_artifact_request` and both need to attach provenance. That's the shared shape — one struct, two builders:

- **CLI builder:** repo URL, branch, commit SHA, dirty flag, file path, CLI version, all from the local environment.
- **MCP builder:** agent name/version, session id, tool name, plus whatever the CLI builder can still determine from cwd.

Optional `model` argument on `deploy_to_canvas` for agent self-report — stored in a **separate field from machine-observed provenance**, since it's attested, not verified.

### `api.rs` request shape

`CreateArtifactRequest` is single-file/E2EE-shaped (`body_ciphertext_b64` + `body_iv_b64`). Bundles need a manifest variant. **This is the v1/v2 dialect seam from §4** — the CLI must speak both, since the free tier keeps the current path indefinitely. Add provenance as optional fields on the existing request now; add the bundle variant when phase 3 lands.

*Incidental cleanup while touching it:* `prepare_artifact_request(&html, tier, ttl, true)` passes an unnamed trailing bool at both call sites. If the signature is changing anyway, make it a named field.

### No users, no migrations

Assume a greenfield rewrite. No `/v2` courtesy endpoint, no compatibility shims, no TTL tail to drain, no fallback for stale configs. `/v1/artifacts` changes shape in place.

**The useful consequence: everything currently in the codebase is now a *choice*, not an inheritance.** Specifically worth re-deciding rather than preserving:

- **Content-addressed IDs everywhere, including the free tier.** No reason to keep 10-char random + read-back collision check anywhere.
- **The `StoredArtifact` KV shape** — redesign to mirror the permanent-mode record so the two modes share a struct where they can.
- **The preview CSP.** Today's `unsafe-inline` / `unsafe-eval` / any-`https:` policy was sized for anonymous ephemeral artifacts. Tighten it now, and derive it per-artifact from the declared external dependencies in §11 rather than shipping one permissive policy for everything.
- **`prepare_artifact_request`'s signature** — no reason to preserve the unnamed trailing bool.

**Decided: the free E2EE ephemeral tier stays.** With no users, nothing forced it to exist — it's kept deliberately, as the intro and the funnel (§8), not inherited. That framing has teeth: the free tier's job is lead generation, so it should be designed *for that job* — the drop-a-file page stays exactly as simple as `.impeccable.md` demands, and the upgrade path from "I shared one link" to "my org needs this" is a product surface worth designing, not an afterthought.

### Suggested order

1. Session state + `clientInfo` capture (self-contained, no server dependency).
2. `--host` flag + `setup.rs` injection (ships together).
3. Provenance struct + both builders, provenance **required** on the request.
4. Replace `CreateArtifactRequest` with the tagged two-mode shape, in place at `/v1`.
5. Bundle/manifest population — defer to phase 3, but the discriminant lands now.
