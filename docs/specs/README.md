# artfct enterprise transition — spec checkpoints

Each spec is independently verifiable and safe to stop at. **Every spec follows the template below and is not done until its DoD checklist and its tests both pass.** All 15 are written — files in `specs/`. Architecture rationale lives in the [decision record](../architecture-decisions.md); product scope in the [PRD](../prd.md).

| # | Spec | Headline DoD |
|---|---|---|
| **0** | [**API contract (OpenAPI)**](00-api-contract.md) | Spec describes the full eventual surface; CI rejects a Worker or CLI change that violates it; `/docs` renders from it |

### Track A — Client (no server dependency, can start today)

| # | Spec | Headline DoD |
|---|---|---|
| 1 | [Client identity](01-client-identity.md) | Deploy from Cursor and Claude Code; each correctly identified, config-injected distinguishable from guessed |
| 2 | [Provenance capture](02-provenance-capture.md) | A deploy carries agent, model, session, repo, branch, commit, dirty flag — with per-field source |

### Track B — Data plane

| # | Spec | Headline DoD |
|---|---|---|
| 3 | [Storage split](03-storage-split.md) | Both modes work; existing `/p/{id}` behaviour unchanged |
| 4 | [Bundle format and API](04-bundle-format.md) | Deploy a real multi-file React bundle well over 1 MB |
| 5 | [Origin isolation](05-origin-isolation.md) | JS in artifact A provably cannot read artifact B |

### Track C — Control plane

| # | Spec | Headline DoD |
|---|---|---|
| 6 | [Identity foundation](06-identity-foundation.md) | Sign up, create an org, invite a member, switch orgs |
| 7 | [Auth seam](07-auth-seam.md) | Authenticated CLI deploy lands in the right tenant; a revoked token stops working |
| 8 | [Admin console and export](08-admin-console.md) | Find an artifact by repo, kill it, export everything the org owns |

### Track D — Tenancy

| # | Spec | Headline DoD |
|---|---|---|
| 9 | [Tenant provisioning](09-tenant-provisioning.md) | Provision a tenant in one command; migrate the fleet and survive a partial failure |

→ **Team tier shippable.**

### Track E — Enterprise

| # | Spec | Headline DoD |
|---|---|---|
| 10 | [Enterprise SSO](10-enterprise-sso.md) | A Team org upgrades to SAML with no duplicate users, orphaned artifacts or admin lockout — and downgrades again |
| 11 | [Governance](11-governance.md) | Answer a real security questionnaire from the product, not from a document |

→ **Enterprise sellable.**

### Track F — Thesis

| # | Spec | Headline DoD |
|---|---|---|
| 12 | [Indexing pipeline](12-indexing-pipeline.md) | A JS-heavy dashboard is searchable by content absent from its source HTML |
| 13 | [Retrieval](13-retrieval.md) | An agent asks for "the billing dashboard from last month" and gets it before rebuilding it |

→ **Thesis testable.**

### Track G — Commercial

| # | Spec | Headline DoD |
|---|---|---|
| 14 | [Billing, quotas, branded console](14-billing-quotas-branded-console.md) | Plan gates, per-tenant quotas, Stripe, `artfct.acme.com` |

---

**Ordering notes**

- **0 comes first.** The payload starts changing in spec 2; three codebases will encode the surface independently unless it's agreed once up front. Provenance field names become D1 columns — renaming them later is a migration.
- **1–2 have no dependencies** and can start immediately.
- **9 is the real milestone.** Everything before it is substrate; it's where revenue becomes possible.
- Reserve later paths in the OpenAPI document but mark them unimplemented. A spec describing endpoints that don't exist is fiction other people will build against.


---

## Spec template

Every spec document has these sections, in this order. A spec without a DoD checklist and a test list is not a spec.

1. **Scope** — what's in, and an explicit list of what's out.
2. **Decisions implemented** — which numbered decisions from the ADR this realises. If it implements none, question why it exists.
3. **Design** — schema, interfaces, contracts. References the OpenAPI document rather than restating it.
4. **Definition of done** — a checklist of *observable* conditions. Each item must be checkable by someone who didn't write the code. "Provenance is captured" is not a DoD item; "a deploy from Claude Code records `agent=claude-code`, `agent_source=config`, and a non-null commit SHA when run inside a git repo" is.
5. **Tests** — the specific cases that must exist, named. Not "add tests."
6. **Rollback** — how to undo it if it's wrong. For specs touching stored data or issued URLs, say explicitly whether it's reversible.
7. **Deferred** — what this deliberately leaves to a later spec, so scope creep is visible.

### DoD rules

- Every DoD item is **observable from outside the code** — a command run, a request made, a page loaded, a value in a table.
- **No item may be "tests written."** Tests are their own section and are a precondition, not an outcome.
- Anything touching **issued URLs or stored artifacts** states its reversibility explicitly.
- A spec that changes the API surface is not done until the **OpenAPI document is updated and contract tests pass** — this applies from spec 0 onward and is the mechanism that keeps three codebases honest.

## Testing baseline

Applies to every spec; individual specs add their own cases on top.

| Layer | Tooling | Required of every spec that touches it |
|---|---|---|
| Worker (Rust/WASM) | `cargo test` | Unit tests for pure logic; integration tests for each handler path, success and failure |
| CLI + MCP (Rust) | `cargo test` | Unit tests; JSON-RPC round-trip tests for MCP methods |
| Laravel | Pest — `php artisan test --compact` | Feature tests over HTTP, using factories; policy/authorization tests wherever a role gates access |
| Console UI | Pest 4 browser tests | The primary flow end to end, plus a smoke pass for JS errors |
| API contract | OpenAPI validation in CI | Requests and responses validated against the document; drift fails the build |

### Tests that are the point of the spec, not a formality

Three specs exist primarily to make a guarantee, and for those an adversarial test *is* the deliverable. A happy-path test does not close them:

- **Spec 5, origin isolation** — a test that actually attempts a cross-artifact read from artifact A's JavaScript and asserts it fails. Also that a signed access token expires, and that a per-artifact CSP blocks an undeclared external origin.
- **Spec 7, auth seam** — a revoked token stops working; a JWT for org A cannot read org B's artifacts; an expired JWT is rejected at the edge.
- **Spec 10, tier upgrade** — the three named failure modes each have a test: mismatched email between AuthKit and the IdP, a user absent from the IdP, and the admin-lockout path. Plus a downgrade test, because only ever testing one direction is how you break someone's login.

Everything with an authorization boundary gets a **negative** test as well as a positive one. Absence of a negative test is the most common way a governance product fails a security review.
