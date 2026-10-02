use super::*;

/// Derives the isolated per-artifact hostname
/// `<tenant-slug>--<artifact-id><suffix>` (spec 05; `suffix` is
/// `ARTIFACT_ORIGIN_SUFFIX` in production, see `artifact_origin_suffix`).
/// Pure function over the slug/id character-set rules already enforced by
/// spec 3 (`store::hostname_label`).
#[allow(
    dead_code,
    reason = "minted by the control plane, not this Worker; exercised directly by hostname_derives_from_slug_and_id"
)]
pub(crate) fn isolated_artifact_hostname(
    tenant_slug: &str,
    artifact_id: &str,
    suffix: &str,
) -> Result<String, String> {
    let label = store::hostname_label(tenant_slug, &store::ArtifactId(artifact_id.to_string()))?;
    Ok(format!("{label}{suffix}"))
}

/// Splits an isolated-origin `Host` header back into `(tenant_slug,
/// artifact_id)`. Returns `None` for any host that isn't under `suffix`
/// (this environment's `artifact_origin_suffix`), including the shared
/// `artfct.dev` host used by free-tier `/p/{id}` links.
pub(crate) fn parse_isolated_hostname(host: &str, suffix: &str) -> Option<(String, String)> {
    let label = host.strip_suffix(suffix)?;
    let (slug, artifact_id) = label.split_once("--")?;
    (!slug.is_empty() && !artifact_id.is_empty())
        .then(|| (slug.to_string(), artifact_id.to_string()))
}

/// HMAC-signed hex signature over `<artifact_id>.<expires_at_unix>`.
pub(crate) fn access_token_signature(
    secret: &str,
    artifact_id: &str,
    expires_at_unix: i64,
) -> String {
    let message = format!("{artifact_id}.{expires_at_unix}");
    store::hmac_sha256(secret.as_bytes(), message.as_bytes())
        .iter()
        .map(|byte| format!("{byte:02x}"))
        .collect()
}

/// Mints a short-lived access token scoped to one artifact. Pure and
/// deterministic given an explicit expiry, so tests never need to sleep.
#[allow(
    dead_code,
    reason = "minted by the control plane, not this Worker; exercised directly by the token tests"
)]
pub(crate) fn mint_access_token(
    secret: &str,
    artifact_id: &str,
    expires_at: chrono::DateTime<Utc>,
) -> String {
    let expires_at_unix = expires_at.timestamp();
    let signature = access_token_signature(secret, artifact_id, expires_at_unix);
    format!("{artifact_id}.{expires_at_unix}.{signature}")
}

/// Verifies an access token against the artifact it is presented for, at a
/// caller-supplied "now". Fails closed: a missing/empty secret, a token
/// minted for a different artifact, an unparseable token, or an expired
/// token are all rejected the same way callers should map to 403.
pub(crate) fn verify_access_token(
    secret: Option<&str>,
    token: &str,
    artifact_id: &str,
    now: chrono::DateTime<Utc>,
) -> bool {
    let Some(secret) = secret.filter(|value| !value.is_empty()) else {
        return false;
    };
    let mut parts = token.splitn(3, '.');
    let (Some(token_artifact_id), Some(expires_at_raw), Some(signature)) =
        (parts.next(), parts.next(), parts.next())
    else {
        return false;
    };
    if !constant_time_equal(token_artifact_id.as_bytes(), artifact_id.as_bytes()) {
        return false;
    }
    let Ok(expires_at_unix) = expires_at_raw.parse::<i64>() else {
        return false;
    };
    if now.timestamp() >= expires_at_unix {
        return false;
    }
    let expected = access_token_signature(secret, token_artifact_id, expires_at_unix);
    constant_time_equal(signature.as_bytes(), expected.as_bytes())
}

const ARTIFACT_ACCESS_COOKIE: &str = "artfct_access";

/// Reads the host-only access cookie used by an isolated artifact's
/// subresources. Duplicate access cookies are rejected rather than choosing
/// one based on header order.
pub(crate) fn access_token_from_cookie(header: Option<&str>) -> Option<String> {
    let mut token = None;

    for pair in header?.split(';') {
        let Some((name, value)) = pair.trim().split_once('=') else {
            continue;
        };
        if name != ARTIFACT_ACCESS_COOKIE {
            continue;
        }

        if token.is_some()
            || value.is_empty()
            || !value
                .bytes()
                .all(|byte| byte.is_ascii_alphanumeric() || matches!(byte, b'.' | b'-' | b'_'))
        {
            return None;
        }

        token = Some(value.to_string());
    }

    token
}

/// Returns a short-lived host-only cookie for a token that has already been
/// verified for the current artifact. No `Domain` attribute is intentional:
/// the browser must not send this credential to another artifact origin.
pub(crate) fn access_token_cookie(token: &str, now: chrono::DateTime<Utc>) -> Option<String> {
    if !token
        .bytes()
        .all(|byte| byte.is_ascii_alphanumeric() || matches!(byte, b'.' | b'-' | b'_'))
    {
        return None;
    }

    let expires_at_unix = token.split('.').nth(1)?.parse::<i64>().ok()?;
    let max_age = expires_at_unix - now.timestamp();
    (max_age > 0).then(|| {
        format!(
            "{ARTIFACT_ACCESS_COOKIE}={token}; Max-Age={max_age}; Path=/; HttpOnly; Secure; SameSite=Strict"
        )
    })
}

/// Derives the per-artifact CSP from the manifest's `external_origins` and
/// `unsafe_eval` flag (spec 05). An artifact declaring nothing gets
/// `default-src 'self'`; `unsafe-eval` is only ever granted when the
/// manifest opts in.
pub(crate) fn isolated_content_security_policy(manifest: &PermanentManifest) -> String {
    let origins = manifest.external_origins.join(" ");
    let default_src = if origins.is_empty() {
        "'self'".to_string()
    } else {
        format!("'self' {origins}")
    };
    let mut script_src = default_src.clone();
    if manifest.unsafe_eval {
        script_src.push_str(" 'unsafe-eval'");
    }
    format!(
        "default-src {default_src}; script-src {script_src}; style-src 'self' 'unsafe-inline'; frame-ancestors 'none'; base-uri 'none'; form-action 'none';"
    )
}

/// Response headers for a permanent bundle file: the real header-
/// construction logic used at the `resolve_permanent_artifact` serving
/// site. Cookie issuance is deliberately separate: only an entrypoint request
/// that already verified a query token may mint the host-only access cookie.
/// Asset responses never mint or widen that cookie.
///
/// `is_isolated` must be true only for a request that actually verified on
/// an isolated `<slug>--<id>.artfct.dev` host — everything else (including
/// every free-tier and legacy shared-origin `/p/{id}` request) gets the
/// pre-spec-05 `PREVIEW_CONTENT_SECURITY_POLICY` byte-identical, so the CSP
/// derived from `external_origins`/`unsafe_eval` never silently tightens an
/// existing artifact that never opted into isolation.
pub(crate) fn permanent_file_response_headers(
    content_type: &str,
    manifest: &PermanentManifest,
    is_isolated: bool,
) -> Vec<(&'static str, String)> {
    let csp = if is_isolated {
        isolated_content_security_policy(manifest)
    } else {
        PREVIEW_CONTENT_SECURITY_POLICY.to_string()
    };
    vec![
        ("Content-Type", content_type.to_string()),
        ("X-Content-Type-Options", "nosniff".to_string()),
        ("Content-Security-Policy", csp),
    ]
}

#[derive(Debug, PartialEq, Eq)]
pub(crate) enum IsolatedAccess {
    /// The request did not arrive on an isolated `<slug>--<id>.artfct.dev`
    /// host — free-tier and legacy shared-origin `/p/{id}` behavior applies
    /// unchanged.
    NotIsolated,
    /// Arrived on this artifact's own isolated host — tenant slug and artifact
    /// id both match the artifact being served — with a token that verifies
    /// for it.
    Authorized,
    /// Arrived on an isolated host that is not this artifact's own origin, or
    /// without a token that verifies for this artifact — callers must reject
    /// with 403.
    Forbidden,
}

/// Decides isolated-origin access from the request `Host` header and an
/// optional bearer/query/cookie token, given an explicit "now" and secret so
/// it is testable without a live Worker. The cookie is a host-only signed
/// artifact token, not a session credential, and is never accepted across
/// artifact origins.
///
/// A verifying token is not sufficient on its own: the host's artifact id and
/// tenant slug must both name the artifact actually being served, or one
/// tenant's isolated origin could serve another artifact (spec 05's one-origin-
/// per-artifact guarantee). A host that is not an isolated origin at all stays
/// `NotIsolated` so shared-origin `/p/{id}` behaviour is untouched.
pub(crate) fn isolated_access_check(
    host: Option<&str>,
    token: Option<&str>,
    artifact_id: &str,
    artifact_org: &str,
    secret: Option<&str>,
    now: chrono::DateTime<Utc>,
    origin_suffix: &str,
) -> IsolatedAccess {
    let Some(host) = host else {
        return IsolatedAccess::NotIsolated;
    };
    let Some((host_org, host_artifact_id)) = parse_isolated_hostname(host, origin_suffix) else {
        return IsolatedAccess::NotIsolated;
    };
    if host_artifact_id != artifact_id || host_org != artifact_org {
        return IsolatedAccess::Forbidden;
    }
    match token {
        Some(token) if verify_access_token(secret, token, artifact_id, now) => {
            IsolatedAccess::Authorized
        }
        _ => IsolatedAccess::Forbidden,
    }
}

pub(crate) fn artifact_token_secret(env: &Env) -> Option<String> {
    env.var(ARTIFACT_TOKEN_SECRET_ENV)
        .ok()
        .map(|value| value.to_string())
}

/// Resolves this environment's isolated-origin suffix: `ARTIFACT_ORIGIN_SUFFIX_ENV`
/// if set (staging), otherwise the production default.
pub(crate) fn artifact_origin_suffix(env: &Env) -> String {
    env.var(ARTIFACT_ORIGIN_SUFFIX_ENV)
        .ok()
        .map(|value| value.to_string())
        .filter(|value| !value.is_empty())
        .unwrap_or_else(|| ARTIFACT_ORIGIN_SUFFIX.to_string())
}

/// 403 response for a rejected isolated-origin request. Uses the plain HTML
/// error path rather than `json_error`/`json_response`, which sets
/// `Access-Control-Allow-Origin: *` on every response — the console and
/// artifact origins must share no CORS allowance (spec 05).
pub(crate) fn isolated_forbidden_response() -> Result<Response> {
    html_error(
        "This access token is invalid, expired, or not valid for this artifact.",
        403,
    )
}
