//! Spec 09 — Workers for Platforms dispatch-namespace checks.
//!
//! Every check this file describes needs a live Cloudflare Workers for
//! Platforms dispatch namespace: a paid tier not enabled in this
//! environment. Unlike `mcp-server/tests/storage_integration.rs` (D1/R2 via
//! a local `wrangler dev`), there is no local equivalent for a dispatch
//! namespace, per-dispatch CPU limits, or Logpush, so there is no
//! environment variable that could gate a real test here the way
//! `storage_integration.rs` gates on `ARTFCT_INTEGRATION_BASE_URL` et al. —
//! there is no local stand-in to point that variable at. Running these for
//! real requires deploying two tenant scripts into a real dispatch
//! namespace (RUB-318, Backlog, blocked on a human decision about that
//! account) and is out of this repository's reach until then.
//!
//! Rather than keep four `#[test]` functions whose bodies are comments
//! describing what they would assert (zero executable assertions, and easy
//! to mistake for coverage that exists), this file is left as this header
//! alone: the record of what spec 09's dispatch-isolation guarantee still
//! needs, and why it can't be checked here. When RUB-318 provisions a real
//! dispatch namespace, the four checks described there — tenant script
//! binding isolation, per-dispatch CPU limit enforcement, `request.cf`
//! unavailability in untrusted mode, and full end-to-end tenant isolation —
//! belong back in this file as real `#[tokio::test]` functions gated the
//! same way `storage_integration.rs` gates on its environment variables.
//!
//! The router's *resolution logic* (hostname -> script name, unknown
//! hostname -> 404 not 500) is pure and unit-tested without any of this
//! infrastructure — see `backend/src/dispatch.rs`.
