//! Spec 09 — Workers for Platforms dispatch routing.
//!
//! This module holds the pure hostname → tenant-script resolution logic for
//! the dispatch Worker (a separate script from the one `lib.rs` otherwise
//! implements, which serves one tenant's artifacts once dispatched to). Only
//! the resolution logic is implemented here — actually calling
//! `env.DISPATCHER.get(script)` against a real Workers for Platforms
//! dispatch namespace needs infrastructure this environment doesn't have
//! (a paid Cloudflare tier, not enabled here); see
//! `backend/tests/dispatch_integration.rs` for what that would cover.

/// Resolves a request `Host` header to a dispatch-namespace script name via
/// an injectable lookup (the real dispatch Worker backs this with a KV
/// read; tests and this function itself don't care how the lookup is
/// implemented, only that it's a pure `Fn` so hostname-resolution
/// correctness is unit-testable without live KV or a dispatch namespace).
///
/// Returns `None` for a hostname with no registered tenant — callers must
/// map that to a 404, never a 500 (spec 09 DoD: "A request for an
/// unprovisioned hostname returns 404, not 500").
pub fn resolve_hostname_to_script<F>(hostname: &str, lookup: F) -> Option<String>
where
    F: Fn(&str) -> Option<String>,
{
    let hostname = hostname.trim().to_ascii_lowercase();
    if hostname.is_empty() {
        return None;
    }
    lookup(&hostname)
}

/// The HTTP status the dispatch Worker must return for an unresolved
/// hostname. Named and tested on its own (mirrors
/// `store::artifact_is_revoked`'s pattern) so the "404, never 500"
/// guarantee has one call site to audit rather than an inline literal
/// scattered across the dispatch router.
pub fn unresolved_hostname_status() -> u16 {
    404
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::collections::HashMap;

    fn registry(pairs: &[(&str, &str)]) -> HashMap<String, String> {
        pairs
            .iter()
            .map(|(host, script)| (host.to_string(), script.to_string()))
            .collect()
    }

    #[test]
    fn router_resolves_hostname_to_script() {
        let registry = registry(&[
            ("acme.artfct.dev", "tenant-acme"),
            ("beta.artfct.dev", "tenant-beta"),
        ]);
        let resolved =
            resolve_hostname_to_script("acme.artfct.dev", |host| registry.get(host).cloned());
        assert_eq!(resolved, Some("tenant-acme".to_string()));

        // Case-insensitivity: Host headers aren't guaranteed lowercase.
        let resolved =
            resolve_hostname_to_script("ACME.artfct.dev", |host| registry.get(host).cloned());
        assert_eq!(resolved, Some("tenant-acme".to_string()));
    }

    #[test]
    fn unknown_hostname_returns_404_not_500() {
        let registry = registry(&[("acme.artfct.dev", "tenant-acme")]);
        let resolved =
            resolve_hostname_to_script("nobody.artfct.dev", |host| registry.get(host).cloned());
        assert_eq!(
            resolved, None,
            "an unregistered hostname must resolve to None, not panic or fall back to any tenant"
        );
        assert_eq!(unresolved_hostname_status(), 404);
    }
}
