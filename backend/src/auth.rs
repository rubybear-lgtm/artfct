use super::*;

pub(crate) async fn require_org_credential(
    authorization: Option<&str>,
    env: &Env,
) -> Result<std::result::Result<OrgCredential, Response>> {
    match resolve_request_credential(authorization, env, Utc::now()).await {
        Ok(credential) => Ok(Ok(credential)),
        Err(error) => Ok(Err(credential_error_response(error)?)),
    }
}

pub(crate) fn authorization_matches(expected: Option<&str>, authorization: Option<&str>) -> bool {
    let Some(expected) = expected.filter(|token| !token.is_empty()) else {
        return false;
    };
    let Some(actual) = authorization
        .and_then(|value| value.strip_prefix("Bearer "))
        .map(str::trim)
        .filter(|token| !token.is_empty())
    else {
        return false;
    };
    constant_time_equal(actual.as_bytes(), expected.as_bytes())
}

/// One key from a published JWKS, in the `n`/`e` (base64url, unpadded) form
/// `DecodingKey::from_rsa_components` expects — no PEM parsing needed.
#[derive(Debug, Clone, Deserialize, Serialize, PartialEq, Eq)]
pub(crate) struct JwkKey {
    pub(crate) kid: String,
    pub(crate) n: String,
    pub(crate) e: String,
}

/// The subset of a JWKS document this Worker needs. Cached in KV by an
/// out-of-band process (Laravel publishes, something populates the cache);
/// this Worker only ever reads the cache — see `cached_jwks`.
#[derive(Debug, Clone, Default, Deserialize, Serialize, PartialEq, Eq)]
pub(crate) struct Jwks {
    pub(crate) keys: Vec<JwkKey>,
}

/// Claims carried by both credential types (spec 07: `sessionJwt` and
/// `orgToken` share one shape; only `exp` distance differs).
#[derive(Debug, Clone, Deserialize, Serialize, PartialEq, Eq)]
pub(crate) struct OrgJwtClaims {
    pub(crate) iss: String,
    pub(crate) aud: String,
    pub(crate) org_id: String,
    pub(crate) user_id: String,
    pub(crate) role: String,
    pub(crate) exp: i64,
    pub(crate) jti: String,
}

/// A verified, non-revoked credential resolved for the current request.
/// `token_id` is the rate-limit/denylist key: the JWT's `jti`, or
/// `LEGACY_ORG_TOKEN_ID` for the pre-spec-07 static `ARTFCT_ORG_TOKEN`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub(crate) struct OrgCredential {
    pub(crate) org_id: String,
    #[allow(dead_code, reason = "carried for future audit logging")]
    pub(crate) user_id: String,
    #[allow(dead_code, reason = "carried for future role-gated routes")]
    pub(crate) role: String,
    pub(crate) token_id: String,
}

/// Every way a credential can fail to resolve. Callers map each variant to a
/// wire response; `Missing` maps to 401 `authentication_required`, every
/// other variant maps to 401 `unauthorized` (spec 07 DoD items 2, 5, 6).
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub(crate) enum CredentialError {
    Missing,
    Malformed,
    UnknownKey,
    BadSignature,
    Expired,
    Revoked,
}

/// Extracts the bearer token from an `Authorization` header, or `None` for
/// anything else (missing header, wrong scheme, empty token).
pub(crate) fn bearer_token(authorization: Option<&str>) -> Option<&str> {
    authorization
        .and_then(|value| value.strip_prefix("Bearer "))
        .map(str::trim)
        .filter(|token| !token.is_empty())
}

/// Pure wrapper around `bearer_token` that maps an absent/malformed
/// `Authorization` header to `CredentialError::Missing` — factored out so
/// the no-credential -> 401 `authentication_required` mapping (spec 07 DoD
/// item 2) is unit-testable without an `Env`, not just the header-parsing
/// helper underneath it.
pub(crate) fn extract_bearer_token(
    authorization: Option<&str>,
) -> std::result::Result<&str, CredentialError> {
    bearer_token(authorization).ok_or(CredentialError::Missing)
}

/// Parses a JWKS document as cached in KV. Pure — no I/O, no `Env` — so the
/// caching layer (`cached_jwks`) and the parsing logic are independently
/// testable.
pub(crate) fn parse_jwks(raw: &str) -> Option<Jwks> {
    serde_json::from_str(raw).ok()
}

/// Verifies an org/session JWT's signature and expiry against an
/// already-resolved JWKS, at a caller-supplied "now". Deliberately takes no
/// `&Env` and performs no I/O of any kind — this is the function whose
/// signature is the proof for spec 07 DoD item 10 ("no origin round-trip"):
/// it cannot reach the network even if it wanted to. Revocation is checked
/// separately by the caller (`resolve_request_credential`), since that does
/// require a KV read.
pub(crate) fn decode_org_jwt(
    token: &str,
    jwks: &Jwks,
    now: chrono::DateTime<Utc>,
    issuer: &str,
    audience: &str,
) -> std::result::Result<OrgJwtClaims, CredentialError> {
    let header = decode_header(token).map_err(|_| CredentialError::Malformed)?;
    let kid = header.kid.ok_or(CredentialError::Malformed)?;
    let key = jwks
        .keys
        .iter()
        .find(|candidate| candidate.kid == kid)
        .ok_or(CredentialError::UnknownKey)?;
    let decoding_key =
        DecodingKey::from_rsa_components(&key.n, &key.e).map_err(|_| CredentialError::Malformed)?;
    let mut validation = Validation::new(Algorithm::RS256);
    // Expiry is checked explicitly below against the caller-supplied clock,
    // never the library's own wall-clock read, so tests never need to sleep
    // or mock global time.
    validation.validate_exp = false;
    validation.set_required_spec_claims(&["exp", "iss", "aud", "org_id", "user_id", "role", "jti"]);
    validation.set_issuer(&[issuer]);
    validation.set_audience(&[audience]);
    let data = decode::<OrgJwtClaims>(token, &decoding_key, &validation)
        .map_err(|_| CredentialError::BadSignature)?;
    if data.claims.exp <= now.timestamp() {
        return Err(CredentialError::Expired);
    }
    Ok(data.claims)
}

/// Combines signature/expiry verification with the revocation check. The
/// denylist lookup is injected as a closure so this stays synchronous and
/// testable with a fake in-memory denylist; the real caller
/// (`resolve_request_credential`) supplies one backed by a KV read it
/// already performed.
pub(crate) fn resolve_org_credential(
    claims: OrgJwtClaims,
    is_denylisted: impl Fn(&str) -> bool,
) -> std::result::Result<OrgCredential, CredentialError> {
    if is_denylisted(&claims.jti) {
        return Err(CredentialError::Revoked);
    }
    Ok(OrgCredential {
        org_id: claims.org_id,
        user_id: claims.user_id,
        role: claims.role,
        token_id: claims.jti,
    })
}

/// Tenancy always comes from the verified credential, never from the
/// request body (spec 07: "An `org_id` in a body or path is ignored if
/// present"). This function exists so that guarantee has one call site to
/// audit, and so `org_id_in_body_is_ignored` can assert it directly instead
/// of asserting the absence of a bug.
pub(crate) fn resolve_tenant_org(_raw: &Value, credential: &OrgCredential) -> String {
    credential.org_id.clone()
}

pub(crate) fn denylist_kv_key(jti: &str) -> String {
    format!("{DENYLIST_KV_PREFIX}{jti}")
}

pub(crate) fn rate_limit_kv_key_for_token(token_id: &str) -> String {
    format!("{RATE_LIMIT_TOKEN_KV_PREFIX}{token_id}")
}

#[allow(
    dead_code,
    reason = "anonymous rate limiting stays on the existing per-IP WAF rule (spec 07 scope); this key derivation exists so the two namespaces are provably disjoint, exercised by anonymous_path_still_rate_limited_by_ip"
)]
pub(crate) fn rate_limit_kv_key_for_ip(ip: &str) -> String {
    format!("{RATE_LIMIT_IP_KV_PREFIX}{ip}")
}

/// Pure threshold check: `current_count` observed *before* this request, so
/// the request that reaches exactly the limit is the one that gets
/// rejected.
pub(crate) fn rate_limit_allows(current_count: u32, limit: u32) -> bool {
    current_count < limit
}

/// Resolves the request's credential end to end: extracts the bearer token,
/// tries the legacy static `ARTFCT_ORG_TOKEN` first (back-compat for specs
/// 03-05, which know nothing about JWTs), then falls back to JWT
/// verification against the cached JWKS plus a live denylist check. The
/// only network-shaped calls here are two KV reads (JWKS cache, denylist) —
/// both edge-local, neither an origin round-trip to Laravel.
pub(crate) async fn resolve_request_credential(
    authorization: Option<&str>,
    env: &Env,
    now: chrono::DateTime<Utc>,
) -> std::result::Result<OrgCredential, CredentialError> {
    let token = extract_bearer_token(authorization)?;

    if let Some(expected) = env
        .var("ARTFCT_ORG_TOKEN")
        .ok()
        .map(|value| value.to_string())
        .filter(|value| !value.is_empty())
    {
        if constant_time_equal(token.as_bytes(), expected.as_bytes()) {
            return Ok(OrgCredential {
                org_id: env_string(env, "ARTFCT_ORG_SLUG", "default"),
                user_id: LEGACY_ORG_TOKEN_ID.to_string(),
                role: "admin".to_string(),
                token_id: LEGACY_ORG_TOKEN_ID.to_string(),
            });
        }
    }

    let jwks = cached_jwks(env).await.ok_or(CredentialError::UnknownKey)?;
    let kv = env
        .kv(KV_BINDING)
        .map_err(|_| CredentialError::UnknownKey)?;
    // Decoded exactly once — `claims` is threaded into `resolve_org_credential`
    // rather than re-decoding, so the denylist check below and the one
    // inside `resolve_org_credential` are provably checking the same `jti`,
    // not just two decodes of the same token that happen to agree today.
    let issuer = env_string(env, "ARTFCT_JWT_ISSUER", "https://artfct.dev");
    let audience = env_string(env, "ARTFCT_JWT_AUDIENCE", "artfct-engine");
    let claims = decode_org_jwt(token, &jwks, now, &issuer, &audience)?;
    let jti = claims.jti.clone();
    let denylisted = kv
        .get(&denylist_kv_key(&jti))
        .text()
        .await
        .ok()
        .flatten()
        .is_some();
    resolve_org_credential(claims, |candidate_jti| candidate_jti == jti && denylisted)
}

/// Reads the JWKS from KV. No fetch fallback: publishing/refreshing the
/// JWKS into KV is an out-of-band concern (Laravel's `.well-known` endpoint
/// plus a job that populates the cache), deliberately outside this
/// function and outside the hot request path (spec 07 DoD item 10).
pub(crate) async fn cached_jwks(env: &Env) -> Option<Jwks> {
    let kv = env.kv(KV_BINDING).ok()?;
    let raw = kv.get(JWKS_KV_KEY).text().await.ok().flatten()?;
    parse_jwks(&raw)
}

/// Checks and increments the per-token rate-limit counter. Not atomic
/// (Workers KV has no compare-and-swap primitive) — an accepted race under
/// heavy concurrent bursts from the same token, same tradeoff class as the
/// eventual-consistency window already accepted for revocation.
pub(crate) async fn check_and_increment_rate_limit(env: &Env, token_id: &str) -> Result<bool> {
    let kv = env.kv(KV_BINDING)?;
    let key = rate_limit_kv_key_for_token(token_id);
    let current = kv
        .get(&key)
        .text()
        .await?
        .and_then(|value| value.parse::<u32>().ok())
        .unwrap_or(0);
    if !rate_limit_allows(current, RATE_LIMIT_MAX_PER_TOKEN) {
        return Ok(false);
    }
    kv.put(&key, (current + 1).to_string())?
        .expiration_ttl(RATE_LIMIT_WINDOW_SECONDS)
        .execute()
        .await?;
    Ok(true)
}

pub(crate) fn credential_error_response(error: CredentialError) -> Result<Response> {
    match error {
        CredentialError::Missing => json_error(
            ErrorCode::AuthenticationRequired,
            "An organization token or session credential is required.",
            401,
        ),
        CredentialError::Revoked => json_error(
            ErrorCode::Unauthorized,
            "This credential has been revoked.",
            401,
        ),
        CredentialError::Expired => {
            json_error(ErrorCode::Unauthorized, "This credential has expired.", 401)
        }
        CredentialError::Malformed
        | CredentialError::UnknownKey
        | CredentialError::BadSignature => {
            json_error(ErrorCode::Unauthorized, "Invalid organization token.", 401)
        }
    }
}
