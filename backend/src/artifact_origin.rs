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
    verified_token_viewer(secret, token, artifact_id, now).is_some()
}

/// Who an access token was minted for (RUB-438). A legacy three-part token
/// (`{id}.{expiry}.{hmac}`) names nobody; a five-part token
/// (`{id}.{expiry}.{viewer}.{p|m}.{hmac}`) names the Laravel user it was minted
/// for and whether that user may see private artifacts in the team (`p`: a team
/// admin, or the `system` renderer).
#[derive(Clone, Debug, PartialEq, Eq)]
pub(crate) struct TokenViewer {
    pub(crate) viewer: Option<String>,
    pub(crate) sees_private: bool,
}

/// [`verify_access_token`], returning who the token names. `None` for every
/// rejection.
pub(crate) fn verified_token_viewer(
    secret: Option<&str>,
    token: &str,
    artifact_id: &str,
    now: chrono::DateTime<Utc>,
) -> Option<TokenViewer> {
    let secret = secret.filter(|value| !value.is_empty())?;
    let parts: Vec<&str> = token.split('.').collect();
    let (token_artifact_id, expires_at_raw, viewer, signature) = match parts.as_slice() {
        [id, expiry, signature] => (*id, *expiry, None, *signature),
        [id, expiry, viewer, scope, signature] => {
            (*id, *expiry, Some((*viewer, *scope)), *signature)
        }
        _ => return None,
    };
    if !constant_time_equal(token_artifact_id.as_bytes(), artifact_id.as_bytes()) {
        return None;
    }
    let expires_at_unix = expires_at_raw.parse::<i64>().ok()?;
    if now.timestamp() >= expires_at_unix {
        return None;
    }
    let expected = match viewer {
        None => access_token_signature(secret, token_artifact_id, expires_at_unix),
        Some((viewer, scope)) => {
            if !valid_token_viewer(viewer) || !matches!(scope, "p" | "m") {
                return None;
            }
            viewer_token_signature(secret, token_artifact_id, expires_at_unix, viewer, scope)
        }
    };
    if !constant_time_equal(signature.as_bytes(), expected.as_bytes()) {
        return None;
    }
    Some(match viewer {
        None => TokenViewer {
            viewer: None,
            sees_private: false,
        },
        Some((viewer, scope)) => TokenViewer {
            viewer: Some(viewer.to_string()),
            sees_private: scope == "p",
        },
    })
}

/// A viewer field Laravel can mint: a user id or `system`, 1 to 64 of
/// `[A-Za-z0-9_-]`, so it can never contain the `.` separator.
fn valid_token_viewer(viewer: &str) -> bool {
    !viewer.is_empty()
        && viewer.len() <= 64
        && viewer
            .bytes()
            .all(|byte| byte.is_ascii_alphanumeric() || byte == b'_' || byte == b'-')
}

/// HMAC over `{id}.{expiry}.{viewer}.{scope}` for a five-part token.
pub(crate) fn viewer_token_signature(
    secret: &str,
    artifact_id: &str,
    expires_at_unix: i64,
    viewer: &str,
    scope: &str,
) -> String {
    let message = format!("{artifact_id}.{expires_at_unix}.{viewer}.{scope}");
    store::hmac_sha256(secret.as_bytes(), message.as_bytes())
        .iter()
        .map(|byte| format!("{byte:02x}"))
        .collect()
}

/// Whether a verified token may open a *private* artifact: only one minted for
/// its owner, or for a viewer who sees every private artifact in the team. A
/// legacy token names nobody, so it never opens a private artifact. This is
/// what makes "make it private" take effect for links minted while the
/// artifact was still shared with the team.
pub(crate) fn token_opens_private(token: &TokenViewer, owner_user_id: Option<&str>) -> bool {
    token.sees_private
        || matches!(
            (token.viewer.as_deref(), owner_user_id),
            (Some(viewer), Some(owner)) if !owner.is_empty() && viewer == owner
        )
}

/// Mints a five-part token naming its viewer. The control plane mints these;
/// this copy exists for the tests.
#[allow(
    dead_code,
    reason = "minted by the control plane; exercised by the token tests"
)]
pub(crate) fn mint_viewer_access_token(
    secret: &str,
    artifact_id: &str,
    expires_at: chrono::DateTime<Utc>,
    viewer: &str,
    sees_private: bool,
) -> String {
    let expires_at_unix = expires_at.timestamp();
    let scope = if sees_private { "p" } else { "m" };
    let signature = viewer_token_signature(secret, artifact_id, expires_at_unix, viewer, scope);
    format!("{artifact_id}.{expires_at_unix}.{viewer}.{scope}.{signature}")
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
#[cfg(test)]
pub(crate) fn isolated_content_security_policy(manifest: &PermanentManifest) -> String {
    isolated_content_security_policy_framed_by(manifest, None)
}

/// [`isolated_content_security_policy`] with `frame-ancestors` opened to one
/// origin: the app's viewer page (RUB-438), which frames the artifact's
/// isolated origin in a sandboxed `<iframe>`. `None` keeps `'none'`, so an
/// environment without `ARTFCT_APP_ORIGIN` cannot be framed by anything.
/// Callers pass only a value that [`frame_ancestor_source`] accepted.
pub(crate) fn isolated_content_security_policy_framed_by(
    manifest: &PermanentManifest,
    frame_ancestor: Option<&str>,
) -> String {
    let frame_ancestors = frame_ancestor.unwrap_or("'none'");
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
        "default-src {default_src}; script-src {script_src}; style-src 'self' 'unsafe-inline'; frame-ancestors {frame_ancestors}; base-uri 'none'; form-action 'none';"
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
///
/// `frame_ancestor` is the app origin allowed to frame an isolated response
/// (see [`isolated_content_security_policy_framed_by`]); it is ignored for a
/// shared-origin response, which is never framable.
///
/// Every permanent response is `Cache-Control: private, no-store`, so a
/// sharing downgrade or a revocation takes effect on the very next request
/// instead of after a browser or shared cache expires.
pub(crate) fn permanent_file_response_headers(
    content_type: &str,
    manifest: &PermanentManifest,
    is_isolated: bool,
    frame_ancestor: Option<&str>,
) -> Vec<(&'static str, String)> {
    let csp = if is_isolated {
        isolated_content_security_policy_framed_by(manifest, frame_ancestor)
    } else {
        PREVIEW_CONTENT_SECURITY_POLICY.to_string()
    };
    vec![
        ("Content-Type", content_type.to_string()),
        ("X-Content-Type-Options", "nosniff".to_string()),
        ("Content-Security-Policy", csp),
        ("Cache-Control", "private, no-store".to_string()),
        // An isolated entrypoint URL carries `?token=`; a manifest's external
        // origins must never receive it in a Referer header.
        ("Referrer-Policy", "no-referrer".to_string()),
    ]
}

/// The CSP source for `ARTFCT_APP_ORIGIN`, or `None` when the value is not a
/// bare origin. Only `https://host[:port]` is accepted, plus `http://` for
/// loopback hosts used by the local e2e stack. Anything carrying a path,
/// query, wildcard, whitespace, quote, comma or semicolon is refused: the
/// value is spliced into a CSP header, so a lax check here would let a bad
/// config add directives or open framing to every origin.
pub(crate) fn frame_ancestor_source(value: &str) -> Option<String> {
    let value = value.trim().trim_end_matches('/');
    let (scheme, authority) = value.split_once("://")?;
    let (host, port) = match authority.rsplit_once(':') {
        Some((host, port)) => (host, Some(port)),
        None => (authority, None),
    };
    let port_ok = port.is_none_or(|port| {
        !port.is_empty() && port.len() <= 5 && port.bytes().all(|byte| byte.is_ascii_digit())
    });
    let host_ok = !host.is_empty()
        && host
            .bytes()
            .all(|byte| byte.is_ascii_alphanumeric() || byte == b'.' || byte == b'-')
        && !host.starts_with(['.', '-'])
        && !host.ends_with(['.', '-']);
    let loopback = matches!(host, "127.0.0.1" | "localhost");
    let scheme_ok = scheme == "https" || (scheme == "http" && loopback);
    (scheme_ok && host_ok && port_ok).then(|| format!("{scheme}://{authority}"))
}

/// This environment's app origin as a frame-ancestors source, if configured
/// and valid. A misconfigured value fails closed to "not framable".
pub(crate) fn app_frame_ancestor(env: &Env) -> Option<String> {
    env.var(APP_ORIGIN_ENV)
        .ok()
        .and_then(|value| frame_ancestor_source(&value.to_string()))
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
