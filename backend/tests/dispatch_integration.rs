//! Spec 09 — Workers for Platforms dispatch-namespace checks.
//!
//! Every test in this file needs a live Cloudflare Workers for Platforms
//! dispatch namespace: a paid tier not enabled in this environment. Unlike
//! `mcp-server/tests/storage_integration.rs` (D1/R2 via a local `wrangler
//! dev`), there is no local equivalent for a dispatch namespace, per-dispatch
//! CPU limits, or Logpush — these can only be verified against a real
//! provisioned Cloudflare account. All four are `#[ignore]`d unconditionally
//! rather than gated on an environment variable that would never be set
//! here; running them for real requires deploying two tenant scripts into a
//! real dispatch namespace and is out of this repository's reach.
//!
//! The router's *resolution logic* (hostname -> script name, unknown
//! hostname -> 404 not 500) is pure and unit-tested without any of this
//! infrastructure — see `backend/src/dispatch.rs`.

#[test]
#[ignore = "requires a live Cloudflare Workers for Platforms dispatch namespace with two provisioned tenants"]
fn tenant_script_cannot_read_other_tenant_bindings() {
    // Would deploy two tenant scripts (acme, beta) into a real dispatch
    // namespace, each with its own D1/R2 bindings, then assert a request
    // dispatched to acme's script cannot read beta's D1 rows or R2 objects
    // even given beta's artifact id — the per-tenant binding isolation
    // Workers for Platforms is chosen specifically to provide (spec 09
    // "Why dispatch namespaces").
}

#[test]
#[ignore = "requires a live Cloudflare Workers for Platforms dispatch namespace with per-dispatch CPU limits configured"]
fn dispatch_cpu_limit_isolates_noisy_tenant() {
    // Would drive one tenant script past its configured per-dispatch CPU
    // limit under concurrent load and assert it alone receives 429s while a
    // second tenant's concurrent requests are unaffected (spec 09 DoD: "A
    // tenant exceeding its per-dispatch CPU limit gets 429 and no other
    // tenant is affected -- verified with concurrent load").
}

#[test]
#[ignore = "requires a live Cloudflare Workers for Platforms dispatch namespace with untrusted-mode scripts"]
fn tenant_scripts_run_untrusted_request_cf_unavailable() {
    // Would deploy a tenant script into the dispatch namespace and assert
    // `request.cf` is unavailable inside it -- Workers for Platforms scripts
    // run untrusted by default (no request.cf, isolated cache, caches.default
    // disabled); spec 09 says "Keep it that way -- the isolation claim is
    // the product."
}

#[test]
#[ignore = "requires a live Cloudflare Workers for Platforms dispatch namespace with two provisioned tenants and end-to-end deploys"]
fn two_tenants_are_fully_isolated_end_to_end() {
    // Would provision two real tenants (acme, beta), deploy with each org's
    // token, and assert acme's token can serve from acme's script/D1 but
    // cannot read beta's artifacts through any path -- the full spec-09
    // isolation guarantee, not just the binding-level unit above.
}
