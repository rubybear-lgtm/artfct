# Spec 5 — Per-artifact origin isolation

**Track:** B (data plane) · **Depends on:** 3, 4 · **Blocks:** nothing, but gates every org-private artifact

## Scope

**In.** One origin per artifact. The flat hostname scheme. Wildcard DNS and certificate. Cookieless serving with short-lived signed access. Per-artifact CSP derived from declared origins.

**Out.** Tenant-branded domains — deferred, and note that branded *artifact* origins need a wildcard custom hostname, which is Cloudflare Enterprise-plan only. The branded *console* is a separate, nearly-free thing in spec 14.

**This spec exists to make a guarantee. A happy-path test does not close it.**

## Decisions implemented

- **06** — flat hostname scheme, `<tenant>--<id>.artfct.dev`, one wildcard level, Universal SSL, no CF for SaaS, $0.
- **03** — per-tenant isolation; this is its per-artifact counterpart.

## Design

### The threat

Today's CSP allows `unsafe-inline`, `unsafe-eval` and any `https:` origin. That is defensible while artifacts are anonymous, ephemeral and encrypted. The moment artifacts contain JavaScript, are org-private, and sit behind ambient session auth on a shared origin, **JavaScript in artifact A can read artifact B**. Same-origin policy is the only thing that would have stopped it, and on a shared origin there is nothing to stop.

### Scheme

`<tenant-slug>--<artifact-id>.artfct.dev` — one wildcard level, covered by Universal SSL. Constraints and their enforcement are inherited from spec 3, which fixed the ID format and the slug rules for exactly this reason.

Two levels (`<id>.artifacts.<tenant>.artfct.dev`) would need Advanced Certificate Manager at ~$10/month. Flat is free and the hierarchy buys nothing.

### Serving

- Artifact origins are **cookieless**. No session cookie is ever scoped to them, so a stolen artifact origin cannot ride a session.
- Access is granted by a **short-lived signed token** in the URL or an `Authorization` header, minted by the control plane, scoped to one artifact.
- The console origin and artifact origins share nothing: no cookie domain, no `postMessage` handler, no CORS allowance.

### CSP

Derived per artifact from `external_origins` in its manifest. An artifact declaring nothing gets `default-src 'self'`. `unsafe-eval` is granted only when the manifest declares it, so the permissive default becomes an opt-in exception that shows up in an audit.

## Definition of done

- [ ] Artifact A and artifact B in the same org serve from different hostnames.
- [ ] A script in artifact A performing `fetch()` against artifact B's URL is blocked by CORS — demonstrated, not assumed.
- [ ] A script in artifact A opening artifact B in an iframe cannot read its DOM.
- [ ] No `Set-Cookie` appears on any artifact-origin response.
- [ ] An access token expires and subsequent requests return 403.
- [ ] A token minted for artifact A returns 403 on artifact B.
- [ ] An artifact declaring no external origins gets `default-src 'self'`, verified in the response header.
- [ ] An artifact loading an undeclared CDN has that request blocked by its CSP.
- [ ] `unsafe-eval` is absent unless the manifest declares it.
- [ ] The wildcard certificate covers `*.artfct.dev` and serves without warning for a maximum-length hostname.
- [ ] Free-tier `/p/{id}` links continue to work unchanged.

## Tests

**`backend`**
- `cross_artifact_read_is_blocked` — the adversarial one; the spec is not done without it
- `cross_artifact_iframe_dom_read_is_blocked` *(negative)*
- `artifact_origin_sets_no_cookie`
- `expired_access_token_rejected` *(negative)*
- `token_for_other_artifact_rejected` *(negative)*
- `csp_defaults_to_self_when_nothing_declared`
- `csp_includes_only_declared_origins`
- `undeclared_origin_absent_from_csp` *(negative)*
- `unsafe_eval_absent_unless_declared` *(negative)*
- `hostname_derives_from_slug_and_id`

**Browser (Pest 4)**
- `artifact_renders_at_isolated_origin`
- `console_session_does_not_authenticate_artifact_origin` *(negative)*

## Rollback

**Irreversible.** This changes the URL of every permanent artifact. Reverting invalidates every link issued under the new scheme. Ship it before any customer shares links, not after — which is why it sits in the data-plane track ahead of tenancy rather than being treated as hardening later.

## Deferred

- Tenant-branded artifact origins — needs a wildcard custom hostname and therefore a CF Enterprise zone. Spec 14 ships the branded console instead, which is nearly free and satisfies most of what buyers mean.
- Subresource integrity enforcement on declared CDNs. Worth doing; not blocking.
