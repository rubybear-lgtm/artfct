//! Spec 20 — the artifact database HTTP surface, served on an artifact's own
//! isolated origin under `/__artfct/db/`.
//!
//! The decisions live in `artifact_db`; this file reads the request, calls
//! them in a fixed order, and talks to D1. Order matters and is the point:
//!
//! 1. the host must be an isolated artifact origin, else 404;
//! 2. the cross-origin write guard runs before any credential is read;
//! 3. the artifact, its owning org and its manifest are loaded by the id in the
//!    **host**, and the host's tenant slug must match the artifact's org;
//! 4. only then is a credential looked at, and `org_id` / `artifact_id` for
//!    every query come from step 3, never from the path, query or body.
//!
//! Responses here never go through `json_response`, which sets
//! `Access-Control-Allow-Origin: *`: an artifact origin shares no CORS
//! allowance with anything (spec 05).

use super::*;
use serde_json::json;

use crate::artifact_db::{
    bytes_added, db_allowed, db_role, exceeds_db_quota, guard_request, index_value, is_db_path,
    mint_db_token, parse_agent_db_path, parse_db_path, parse_document_body, verify_db_token,
    within_write_rate, BodyRefusal, DbGrant, DbLimits, DbManifest, DbRollout, DbRoute, GuardFacts,
    DB_PATH_PREFIX, DB_REQUEST_HEADER, DB_TOKEN_TTL_SECS, DEFAULT_PAGE_LIMIT, MAX_BATCH_WRITES,
    MAX_BODY_BYTES, MAX_PAGE_LIMIT,
};
use crate::artifact_origin::{
    access_token_from_cookie, artifact_origin_suffix, artifact_token_secret,
    parse_isolated_hostname, verified_token_viewer, TokenViewer,
};
use crate::sharing::{EditAccess, Sharing};

const DB_ARTIFACT_SQL: &str = "SELECT a.id AS id, a.tier AS tier, a.user_id AS user_id, a.edit_access AS edit_access, a.manifest AS manifest, o.slug AS org FROM artifacts a JOIN orgs o ON o.id = a.org_id WHERE a.id = ? AND a.revoked_at IS NULL AND (a.expires_at IS NULL OR a.expires_at > ?) AND NOT EXISTS (SELECT 1 FROM json_each(a.manifest, '$.files') mf WHERE NOT EXISTS (SELECT 1 FROM files complete WHERE complete.artifact_row_id = a.row_id AND complete.path = json_extract(mf.value, '$.path'))) ORDER BY a.row_id LIMIT 1";

#[derive(Debug, Deserialize)]
struct DbArtifactRow {
    id: String,
    tier: String,
    user_id: Option<String>,
    edit_access: Option<String>,
    manifest: String,
    org: String,
}

#[derive(Debug, Deserialize)]
struct DocRow {
    doc_id: String,
    body: String,
    version: f64,
    updated_at: String,
    updated_by: String,
}

#[derive(Debug, Deserialize)]
struct UsageRow {
    docs: f64,
    bytes: f64,
}

/// A JSON response with no CORS headers, never cached, never sniffed.
fn db_json(value: Value, status: u16) -> Result<Response> {
    let mut response = Response::from_json(&value)?.with_status(status);
    let headers = response.headers_mut();
    headers.set("X-Content-Type-Options", "nosniff")?;
    headers.set("Cache-Control", "private, no-store")?;
    headers.set("Referrer-Policy", "no-referrer")?;
    Ok(response)
}

fn db_error(code: &str, message: &str, status: u16) -> Result<Response> {
    db_json(json!({"error": {"code": code, "message": message}}), status)
}

fn db_not_found() -> Result<Response> {
    db_error("not_found", "Not found.", 404)
}

/// Answers every preflight with a bare 405 and no `Access-Control-*`
/// headers, so a cross-origin caller's preflight fails and its real request
/// is never sent.
fn db_preflight_refused() -> Result<Response> {
    let mut response = Response::empty()?.with_status(405);
    response.headers_mut().set("Cache-Control", "no-store")?;
    Ok(response)
}

fn header(req: &Request, name: &str) -> Result<Option<String>> {
    req.headers().get(name)
}

fn bearer_token(req: &Request) -> Result<Option<String>> {
    Ok(header(req, "Authorization")?
        .as_deref()
        .and_then(|value| value.strip_prefix("Bearer "))
        .map(str::trim)
        .filter(|value| !value.is_empty())
        .map(str::to_string))
}

fn query_param(req: &Request, key: &str) -> Result<Option<String>> {
    Ok(req
        .url()?
        .query_pairs()
        .find(|(name, _)| name == key)
        .map(|(_, value)| value.into_owned()))
}

/// `?if_version=N`: `None` when absent, `Err` when present but not a
/// non-negative integer.
fn if_version(req: &Request) -> Result<std::result::Result<Option<u64>, ()>> {
    Ok(match query_param(req, "if_version")? {
        None => Ok(None),
        Some(raw) => raw.parse::<u64>().map(Some).map_err(|_| ()),
    })
}

fn encode_body(map: &serde_json::Map<String, Value>) -> String {
    serde_json::to_string(map).unwrap_or_else(|_| "{}".to_string())
}

fn doc_json(collection: &str, row: &DocRow) -> Value {
    json!({
        "id": row.doc_id,
        "collection": collection,
        "data": serde_json::from_str::<Value>(&row.body).unwrap_or(Value::Null),
        "version": row.version as u64,
        "updated_at": row.updated_at,
        "updated_by": row.updated_by,
    })
}

/// The environment variable that releases the artifact database.
const ROLLOUT_ENV: &str = "ARTFCT_ARTIFACT_DB_ENABLED";

pub(crate) fn db_rollout(env: &Env) -> DbRollout {
    env.var(ROLLOUT_ENV)
        .ok()
        .map_or(DbRollout::Off, |value| DbRollout::parse(&value.to_string()))
}

#[derive(Debug, Deserialize)]
struct OrgDbSettingRow {
    artifact_db_enabled: Option<i64>,
}

/// Whether the artifact database is released to `org`: the environment's
/// rollout, narrowed by the org's explicit setting. An `Off` environment
/// answers without touching D1.
pub(crate) async fn db_enabled_for_org(
    database: &worker::D1Database,
    env: &Env,
    org: &str,
) -> Result<bool> {
    let rollout = db_rollout(env);
    if rollout == DbRollout::Off {
        return Ok(false);
    }
    let setting = database
        .prepare("SELECT artifact_db_enabled FROM org_settings WHERE org_id = ?")
        .bind(&[JsValue::from_str(org)])?
        .first::<OrgDbSettingRow>(None)
        .await?
        .and_then(|row| row.artifact_db_enabled)
        .map(|value| value != 0);
    Ok(db_allowed(rollout, setting))
}

/// Entry point from the Worker's `fetch`. Returns `None` for a request that
/// is not in the db namespace so the normal router keeps it.
pub(crate) async fn artifact_db_route(req: &mut Request, env: &Env) -> Option<Result<Response>> {
    let url = req.url().ok()?;
    if let Some((artifact_id, rest)) = parse_agent_db_path(url.path()) {
        if db_rollout(env) == DbRollout::Off {
            return Some(db_not_found());
        }
        return Some(handle_agent(req, env, artifact_id, rest).await);
    }
    if !is_db_path(url.path()) {
        return None;
    }
    // Released dark: with the environment off the whole namespace is a 404,
    // answered before any D1 query.
    if db_rollout(env) == DbRollout::Off {
        return Some(db_not_found());
    }
    Some(handle(req, env, url.path().to_string()).await)
}

/// The agent API: `/v1/artifacts/{id}/db/...`, authenticated by an org
/// credential with `data:read` or `data:write`. There is no browser here, so
/// the origin guard does not apply; everything the artifact origin enforces
/// after authentication (role, read-only org, rate limit, quotas) still does,
/// through [`dispatch`]. Anything the caller may not see is a 404.
async fn handle_agent(
    req: &mut Request,
    env: &Env,
    artifact_id: String,
    rest: String,
) -> Result<Response> {
    let method = req.method();
    if method == Method::Options {
        return db_preflight_refused();
    }
    let is_write = !matches!(method, Method::Get | Method::Head);
    let scope = if is_write { "data:write" } else { "data:read" };
    let authorization = header(req, "Authorization")?;
    let credential =
        match crate::auth::require_org_scope(authorization.as_deref(), env, scope).await? {
            Ok(credential) => credential,
            Err(refusal) => return Ok(refusal),
        };
    let Some(route) = parse_db_path(&format!("{DB_PATH_PREFIX}{rest}")) else {
        return db_not_found();
    };
    if route == DbRoute::Session {
        return db_not_found();
    }
    if is_write
        && header(req, "Content-Length")?
            .and_then(|value| value.parse::<usize>().ok())
            .is_some_and(|length| length > MAX_BODY_BYTES * 4)
    {
        return body_refusal_response(BodyRefusal::TooLarge);
    }
    let database = env.d1("ARTIFACTS_DB")?;
    let now = Utc::now();
    let Some(row) = database
        .prepare(DB_ARTIFACT_SQL)
        .bind(&[
            JsValue::from_str(&artifact_id),
            JsValue::from_str(&now.to_rfc3339()),
        ])?
        .first::<DbArtifactRow>(None)
        .await?
    else {
        return db_not_found();
    };
    if row.org != credential.org_id || !db_enabled_for_org(&database, env, &row.org).await? {
        return db_not_found();
    }
    let Some(declared) = declared_db(&row.manifest) else {
        return db_not_found();
    };
    let viewer = crate::sharing::Viewer::from_credential(&credential);
    let token_viewer = TokenViewer {
        viewer: Some(credential.user_id.clone()),
        sees_private: viewer.is_admin() || viewer.reads_private(),
    };
    let Some(role) = db_role(
        Some(&token_viewer),
        row.user_id.as_deref(),
        Sharing::from_tier(&row.tier),
        EditAccess::from_stored(row.edit_access.as_deref().unwrap_or("view")),
    ) else {
        return db_not_found();
    };
    let grant = DbGrant {
        viewer: format!("agent:{}", credential.user_id)
            .chars()
            .take(64)
            .collect(),
        role,
    };
    dispatch(
        req, env, &database, &row, &declared, route, method, &grant, now,
    )
    .await
}

async fn handle(req: &mut Request, env: &Env, path: String) -> Result<Response> {
    let method = req.method();
    if method == Method::Options {
        return db_preflight_refused();
    }
    let host = header(req, "Host")?;
    let suffix = artifact_origin_suffix(env);
    let Some((host_slug, artifact_id)) = host
        .as_deref()
        .and_then(|host| parse_isolated_hostname(host, &suffix))
    else {
        return db_not_found();
    };

    let is_write = !matches!(method, Method::Get | Method::Head);
    let sec_fetch_site = header(req, "Sec-Fetch-Site")?;
    let origin = header(req, "Origin")?;
    let content_type = header(req, "Content-Type")?;
    let request_header = header(req, DB_REQUEST_HEADER)?;
    // The scheme is the connection's own (https at the edge), never a header a
    // caller can set; the local dev Worker is http and rewrites `Origin` to match.
    let scheme = req.url()?.scheme().to_string();
    let facts = GuardFacts {
        is_write,
        scheme: &scheme,
        host: host.as_deref(),
        sec_fetch_site: sec_fetch_site.as_deref(),
        origin: origin.as_deref(),
        content_type: content_type.as_deref(),
        request_header: request_header.as_deref(),
    };
    if guard_request(&facts).is_err() {
        return db_error(
            "forbidden",
            "This request is not allowed from this origin.",
            403,
        );
    }

    // Refuse an obviously oversized body before reading any of it.
    if is_write
        && header(req, "Content-Length")?
            .and_then(|value| value.parse::<usize>().ok())
            .is_some_and(|length| length > MAX_BODY_BYTES * 4)
    {
        return body_refusal_response(BodyRefusal::TooLarge);
    }

    let database = env.d1("ARTIFACTS_DB")?;
    let now = Utc::now();
    let Some(row) = database
        .prepare(DB_ARTIFACT_SQL)
        .bind(&[
            JsValue::from_str(&artifact_id),
            JsValue::from_str(&now.to_rfc3339()),
        ])?
        .first::<DbArtifactRow>(None)
        .await?
    else {
        return db_not_found();
    };
    // One origin per artifact: the host's tenant must own the artifact.
    if row.org != host_slug || row.id != artifact_id {
        return db_not_found();
    }
    if !db_enabled_for_org(&database, env, &row.org).await? {
        return db_not_found();
    }
    let Some(declared) = declared_db(&row.manifest) else {
        return db_not_found();
    };
    let Some(route) = parse_db_path(&path) else {
        return db_not_found();
    };

    let secret = artifact_token_secret(env);
    if route == DbRoute::Session {
        if method != Method::Get {
            return db_error("method_not_allowed", "Use GET.", 405);
        }
        return session(req, &row, secret.as_deref(), now);
    }

    let Some(grant) = bearer_token(req)?
        .and_then(|token| verify_db_token(secret.as_deref(), &token, &row.id, now))
    else {
        return db_error("unauthorized", "A valid database token is required.", 401);
    };
    dispatch(
        req, env, &database, &row, &declared, route, method, &grant, now,
    )
    .await
}

/// Everything after authentication, shared by the artifact origin (a db token)
/// and the agent API (an org credential): the write-permission check, the
/// read-only org check, the rate charge, quotas, and the routing itself.
#[allow(clippy::too_many_arguments, reason = "one argument per request fact")]
async fn dispatch(
    req: &mut Request,
    env: &Env,
    database: &worker::D1Database,
    row: &DbArtifactRow,
    declared: &DbManifest,
    route: DbRoute,
    method: Method,
    grant: &DbGrant,
    now: chrono::DateTime<Utc>,
) -> Result<Response> {
    let is_write = !matches!(method, Method::Get | Method::Head);
    if is_write && !grant.role.can_write() {
        return db_error("forbidden", "This viewer cannot write.", 403);
    }
    let mut limits = DbLimits::default();
    if is_write {
        // A read-only org (payment state) can read but not write. Checked
        // first so a refused write does not spend the viewer's budget.
        if crate::org_admin::load_org_limits(database, env, &row.org)
            .await?
            .read_only
        {
            return db_error("read_only", "This team's workspace is read-only.", 403);
        }
        // A batch is charged per write inside `batch`, once its size is known.
        if route != DbRoute::Batch
            && !charge_write_rate(database, &row.id, &grant.viewer, 1, now).await?
        {
            return rate_limited_response();
        }
        limits = load_db_limits(database, &row.org).await?;
    }

    match (route, method) {
        (DbRoute::Schema, Method::Get) => {
            db_json(json!({"collections": declared.collections}), 200)
        }
        (DbRoute::Doc { collection, doc_id }, Method::Get) => {
            read_doc(database, declared, row, &collection, &doc_id).await
        }
        (DbRoute::Collection { collection }, Method::Get) => {
            list_docs(req, database, declared, row, &collection).await
        }
        (DbRoute::Doc { collection, doc_id }, Method::Put) => {
            put_doc(
                req,
                database,
                declared,
                row,
                grant,
                &limits,
                &collection,
                &doc_id,
                now,
            )
            .await
        }
        (DbRoute::Doc { collection, doc_id }, Method::Patch) => {
            patch_doc(
                req,
                database,
                declared,
                row,
                grant,
                &limits,
                &collection,
                &doc_id,
                now,
            )
            .await
        }
        (DbRoute::Doc { collection, doc_id }, Method::Delete) => {
            delete_doc(req, database, declared, row, &collection, &doc_id).await
        }
        (DbRoute::Batch, Method::Post) => {
            batch(req, database, declared, row, grant, &limits, now).await
        }
        _ => db_error(
            "method_not_allowed",
            "That method is not supported here.",
            405,
        ),
    }
}

/// The manifest's `db` section, or `None` when the artifact declares none
/// (then it has no db endpoint at all).
fn declared_db(manifest: &str) -> Option<DbManifest> {
    serde_json::from_str::<Value>(manifest)
        .ok()?
        .get("db")
        .cloned()
        .and_then(|value| serde_json::from_value::<DbManifest>(value).ok())
        .filter(|declared| !declared.collections.is_empty())
}

/// `GET /__artfct/db/session`: exchanges the host-only access cookie for a
/// short-lived bearer write token. This is the only db endpoint that reads the
/// cookie, and the same-origin guard has already run.
fn session(
    req: &Request,
    row: &DbArtifactRow,
    secret: Option<&str>,
    now: chrono::DateTime<Utc>,
) -> Result<Response> {
    let cookie = access_token_from_cookie(header(req, "Cookie")?.as_deref());
    let token_viewer = cookie
        .as_deref()
        .and_then(|token| verified_token_viewer(secret, token, &row.id, now));
    let sharing = Sharing::from_tier(&row.tier);
    let edit_access = EditAccess::from_stored(row.edit_access.as_deref().unwrap_or("view"));
    let Some(role) = db_role(
        token_viewer.as_ref(),
        row.user_id.as_deref(),
        sharing,
        edit_access,
    ) else {
        return db_error("forbidden", "No access to this artifact's data.", 403);
    };
    let viewer = token_viewer
        .as_ref()
        .and_then(|token| token.viewer.clone())
        .unwrap_or_else(|| "anon".to_string());
    let expires_at = now + chrono::Duration::seconds(DB_TOKEN_TTL_SECS);
    let Some(secret) = secret.filter(|value| !value.is_empty()) else {
        return db_error("unavailable", "Database access is not configured.", 503);
    };
    let Some(token) = mint_db_token(secret, &row.id, expires_at, &viewer, role) else {
        return db_error("forbidden", "No access to this artifact's data.", 403);
    };
    db_json(
        json!({
            "token": token,
            "expires_at": expires_at.to_rfc3339_opts(SecondsFormat::Secs, true),
            "role": role.as_str(),
        }),
        200,
    )
}

fn declared_collection<'a>(
    declared: &'a DbManifest,
    collection: &str,
) -> Option<&'a crate::artifact_db::DbCollectionDecl> {
    declared.collection(collection)
}

async fn fetch_doc(
    database: &worker::D1Database,
    row: &DbArtifactRow,
    collection: &str,
    doc_id: &str,
) -> Result<Option<DocRow>> {
    database
        .prepare("SELECT doc_id, body, version, updated_at, updated_by FROM artifact_docs WHERE org_id = ? AND artifact_id = ? AND collection = ? AND doc_id = ?")
        .bind(&[
            JsValue::from_str(&row.org),
            JsValue::from_str(&row.id),
            JsValue::from_str(collection),
            JsValue::from_str(doc_id),
        ])?
        .first::<DocRow>(None)
        .await
}

async fn read_doc(
    database: &worker::D1Database,
    declared: &DbManifest,
    row: &DbArtifactRow,
    collection: &str,
    doc_id: &str,
) -> Result<Response> {
    if declared_collection(declared, collection).is_none() {
        return db_error(
            "unknown_collection",
            "That collection is not declared.",
            422,
        );
    }
    match fetch_doc(database, row, collection, doc_id).await? {
        Some(doc) => db_json(doc_json(collection, &doc), 200),
        None => db_not_found(),
    }
}

async fn list_docs(
    req: &Request,
    database: &worker::D1Database,
    declared: &DbManifest,
    row: &DbArtifactRow,
    collection: &str,
) -> Result<Response> {
    let Some(declaration) = declared_collection(declared, collection) else {
        return db_error(
            "unknown_collection",
            "That collection is not declared.",
            422,
        );
    };
    let limit = query_param(req, "limit")?
        .and_then(|raw| raw.parse::<u32>().ok())
        .unwrap_or(DEFAULT_PAGE_LIMIT)
        .clamp(1, MAX_PAGE_LIMIT);
    let cursor = query_param(req, "cursor")?.unwrap_or_default();
    let index_eq = query_param(req, "index_eq")?;
    if index_eq.is_some() && declaration.index.is_none() {
        return db_error("no_index", "That collection declares no index field.", 422);
    }
    let mut sql = String::from(
        "SELECT doc_id, body, version, updated_at, updated_by FROM artifact_docs WHERE org_id = ? AND artifact_id = ? AND collection = ? AND doc_id > ?",
    );
    let mut binds = vec![
        JsValue::from_str(&row.org),
        JsValue::from_str(&row.id),
        JsValue::from_str(collection),
        JsValue::from_str(&cursor),
    ];
    if let Some(value) = &index_eq {
        sql.push_str(" AND index_value = ?");
        binds.push(JsValue::from_str(value));
    }
    sql.push_str(" ORDER BY doc_id LIMIT ?");
    // One extra row tells us whether another page exists.
    binds.push(JsValue::from_f64(f64::from(limit) + 1.0));
    let docs = database
        .prepare(sql)
        .bind(&binds)?
        .all()
        .await?
        .results::<DocRow>()?;
    let has_more = docs.len() > limit as usize;
    let page: Vec<&DocRow> = docs.iter().take(limit as usize).collect();
    let next_cursor = has_more
        .then(|| page.last().map(|doc| doc.doc_id.clone()))
        .flatten();
    db_json(
        json!({
            "collection": collection,
            "documents": page.iter().map(|doc| doc_json(collection, doc)).collect::<Vec<_>>(),
            "next_cursor": next_cursor,
        }),
        200,
    )
}

/// `(documents in this artifact, bytes in this org)`: the two quotas count
/// different scopes.
async fn usage(database: &worker::D1Database, row: &DbArtifactRow) -> Result<(u64, u64)> {
    let usage = database
        .prepare("SELECT (SELECT COUNT(*) FROM artifact_docs WHERE org_id = ? AND artifact_id = ?) AS docs, (SELECT COALESCE(SUM(LENGTH(CAST(body AS BLOB))), 0) FROM artifact_docs WHERE org_id = ?) AS bytes")
        .bind(&[
            JsValue::from_str(&row.org),
            JsValue::from_str(&row.id),
            JsValue::from_str(&row.org),
        ])?
        .first::<UsageRow>(None)
        .await?;
    Ok(usage.map_or((0, 0), |usage| (usage.docs as u64, usage.bytes as u64)))
}

#[derive(Debug, Deserialize)]
struct RateRow {
    writes: f64,
}

fn rate_limited_response() -> Result<Response> {
    db_error("rate_limited", "Too many writes. Try again shortly.", 429)
}

/// Charges `writes` against this viewer's one-minute budget for this artifact
/// and reports whether it still fits. One atomic D1 statement: parallel writes
/// each see the true running count.
async fn charge_write_rate(
    database: &worker::D1Database,
    artifact_id: &str,
    viewer: &str,
    writes: u64,
    now: chrono::DateTime<Utc>,
) -> Result<bool> {
    let minute = now.timestamp() / 60;
    let row = database
        .prepare("INSERT INTO artifact_db_rate (artifact_id, viewer, minute, writes) VALUES (?, ?, ?, ?) ON CONFLICT(artifact_id, viewer, minute) DO UPDATE SET writes = writes + excluded.writes RETURNING writes")
        .bind(&[
            JsValue::from_str(artifact_id),
            JsValue::from_str(viewer),
            JsValue::from_f64(minute as f64),
            JsValue::from_f64(writes as f64),
        ])?
        .first::<RateRow>(None)
        .await?;
    let total = row.map_or(writes, |row| row.writes as u64);
    if total == writes {
        // First charge of a new minute: drop this viewer's old buckets.
        database
            .prepare(
                "DELETE FROM artifact_db_rate WHERE artifact_id = ? AND viewer = ? AND minute < ?",
            )
            .bind(&[
                JsValue::from_str(artifact_id),
                JsValue::from_str(viewer),
                JsValue::from_f64((minute - 5) as f64),
            ])?
            .run()
            .await?;
    }
    Ok(within_write_rate(total))
}

#[derive(Debug, Deserialize)]
struct DbLimitsRow {
    db_docs_per_artifact: Option<i64>,
    db_bytes_per_org: Option<i64>,
}

/// The org's pushed db quotas; NULL columns, or no row, mean the defaults.
async fn load_db_limits(database: &worker::D1Database, org: &str) -> Result<DbLimits> {
    let row = database
        .prepare("SELECT db_docs_per_artifact, db_bytes_per_org FROM org_limits WHERE org_id = ?")
        .bind(&[JsValue::from_str(org)])?
        .first::<DbLimitsRow>(None)
        .await?;
    Ok(row.map_or_else(DbLimits::default, |row| {
        DbLimits::from_pushed(row.db_docs_per_artifact, row.db_bytes_per_org)
    }))
}

fn body_refusal_response(refusal: BodyRefusal) -> Result<Response> {
    match refusal {
        BodyRefusal::TooLarge => db_error(
            "too_large",
            &format!("A document may be at most {MAX_BODY_BYTES} bytes."),
            413,
        ),
        BodyRefusal::NotJson => db_error("invalid_json", "The body is not valid JSON.", 400),
        BodyRefusal::NotAnObject => {
            db_error("invalid_document", "A document must be a JSON object.", 422)
        }
        BodyRefusal::ServerFieldPresent(field) => db_error(
            "reserved_field",
            &format!("`{field}` is set by the server and cannot be written."),
            422,
        ),
    }
}

fn conflict(current: &DocRow, collection: &str) -> Result<Response> {
    db_json(
        json!({
            "error": {"code": "version_conflict", "message": "The document changed."},
            "current": doc_json(collection, current),
        }),
        409,
    )
}

fn quota_response() -> Result<Response> {
    db_error(
        "quota_exceeded",
        "This artifact's database is full. Delete documents to write more.",
        429,
    )
}

fn written(collection: &str, doc_id: &str, version: u64, updated_at: &str) -> Result<Response> {
    db_json(
        json!({
            "id": doc_id,
            "collection": collection,
            "version": version,
            "updated_at": updated_at,
        }),
        200,
    )
}

#[allow(clippy::too_many_arguments, reason = "one argument per column written")]
fn upsert_statement(
    database: &worker::D1Database,
    row: &DbArtifactRow,
    collection: &str,
    doc_id: &str,
    body: &str,
    index: Option<&str>,
    grant: &DbGrant,
    updated_at: &str,
) -> Result<worker::D1PreparedStatement> {
    database
        .prepare("INSERT INTO artifact_docs (org_id, artifact_id, collection, doc_id, body, index_value, version, updated_at, updated_by) VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?) ON CONFLICT(org_id, artifact_id, collection, doc_id) DO UPDATE SET body = excluded.body, index_value = excluded.index_value, version = artifact_docs.version + 1, updated_at = excluded.updated_at, updated_by = excluded.updated_by")
        .bind(&[
            JsValue::from_str(&row.org),
            JsValue::from_str(&row.id),
            JsValue::from_str(collection),
            JsValue::from_str(doc_id),
            JsValue::from_str(body),
            index.map(JsValue::from_str).unwrap_or_else(JsValue::null),
            JsValue::from_str(updated_at),
            JsValue::from_str(&grant.viewer),
        ])
}

#[derive(Debug, Deserialize)]
struct VersionRow {
    version: f64,
}

/// Writes one document atomically and returns the version it now has, or
/// `None` when an `if_version` precondition no longer holds. The precondition
/// is part of the statement, so two concurrent `if_version=N` writes cannot
/// both win: exactly one statement finds `version = N`.
#[allow(clippy::too_many_arguments, reason = "one argument per column written")]
async fn write_doc(
    database: &worker::D1Database,
    row: &DbArtifactRow,
    collection: &str,
    doc_id: &str,
    body: &str,
    index: Option<&str>,
    grant: &DbGrant,
    updated_at: &str,
    expected: Option<u64>,
) -> Result<Option<u64>> {
    let index_js = index.map(JsValue::from_str).unwrap_or_else(JsValue::null);
    let insert_binds = [
        JsValue::from_str(&row.org),
        JsValue::from_str(&row.id),
        JsValue::from_str(collection),
        JsValue::from_str(doc_id),
        JsValue::from_str(body),
        index_js.clone(),
        JsValue::from_str(updated_at),
        JsValue::from_str(&grant.viewer),
    ];
    let statement = match expected {
        // Create only: nothing may exist yet.
        Some(0) => database
            .prepare("INSERT INTO artifact_docs (org_id, artifact_id, collection, doc_id, body, index_value, version, updated_at, updated_by) VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?) ON CONFLICT(org_id, artifact_id, collection, doc_id) DO NOTHING RETURNING version")
            .bind(&insert_binds)?,
        // Replace exactly version N.
        Some(version) => database
            .prepare("UPDATE artifact_docs SET body = ?, index_value = ?, version = version + 1, updated_at = ?, updated_by = ? WHERE org_id = ? AND artifact_id = ? AND collection = ? AND doc_id = ? AND version = ? RETURNING version")
            .bind(&[
                JsValue::from_str(body),
                index_js,
                JsValue::from_str(updated_at),
                JsValue::from_str(&grant.viewer),
                JsValue::from_str(&row.org),
                JsValue::from_str(&row.id),
                JsValue::from_str(collection),
                JsValue::from_str(doc_id),
                JsValue::from_f64(version as f64),
            ])?,
        // Last write wins.
        None => database
            .prepare("INSERT INTO artifact_docs (org_id, artifact_id, collection, doc_id, body, index_value, version, updated_at, updated_by) VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?) ON CONFLICT(org_id, artifact_id, collection, doc_id) DO UPDATE SET body = excluded.body, index_value = excluded.index_value, version = artifact_docs.version + 1, updated_at = excluded.updated_at, updated_by = excluded.updated_by RETURNING version")
            .bind(&insert_binds)?,
    };
    Ok(statement
        .first::<VersionRow>(None)
        .await?
        .map(|written| written.version as u64))
}

#[allow(clippy::too_many_arguments, reason = "one argument per request fact")]
async fn put_doc(
    req: &mut Request,
    database: &worker::D1Database,
    declared: &DbManifest,
    row: &DbArtifactRow,
    grant: &DbGrant,
    limits: &DbLimits,
    collection: &str,
    doc_id: &str,
    now: chrono::DateTime<Utc>,
) -> Result<Response> {
    let Some(declaration) = declared_collection(declared, collection) else {
        return db_error(
            "unknown_collection",
            "That collection is not declared.",
            422,
        );
    };
    let Ok(expected) = if_version(req)? else {
        return db_error(
            "invalid_if_version",
            "`if_version` must be an integer.",
            400,
        );
    };
    let bytes = req.bytes().await?;
    let body = match parse_document_body(&bytes) {
        Ok(body) => body,
        Err(refusal) => return body_refusal_response(refusal),
    };
    let existing = fetch_doc(database, row, collection, doc_id).await?;
    match (&existing, expected) {
        (Some(current), Some(version)) if current.version as u64 != version => {
            return conflict(current, collection);
        }
        (None, Some(version)) if version != 0 => return db_not_found(),
        (Some(current), Some(0)) => return conflict(current, collection),
        _ => {}
    }
    let encoded = encode_body(&body);
    let added = bytes_added(
        encoded.len() as u64,
        existing.as_ref().map(|current| current.body.len() as u64),
    );
    if existing.is_none() || added > 0 {
        let (docs, org_bytes) = usage(database, row).await?;
        if exceeds_db_quota(
            limits,
            docs,
            org_bytes,
            u64::from(existing.is_none()),
            added,
        ) {
            return quota_response();
        }
    }
    let updated_at = now.to_rfc3339_opts(SecondsFormat::Millis, true);
    let index = declaration
        .index
        .as_deref()
        .and_then(|field| index_value(&body, field));
    let Some(version) = write_doc(
        database,
        row,
        collection,
        doc_id,
        &encoded,
        index.as_deref(),
        grant,
        &updated_at,
        expected,
    )
    .await?
    else {
        // The precondition failed between the read above and the write.
        return match fetch_doc(database, row, collection, doc_id).await? {
            Some(latest) => conflict(&latest, collection),
            None => db_not_found(),
        };
    };
    written(collection, doc_id, version, &updated_at)
}

#[allow(clippy::too_many_arguments, reason = "one argument per request fact")]
async fn patch_doc(
    req: &mut Request,
    database: &worker::D1Database,
    declared: &DbManifest,
    row: &DbArtifactRow,
    grant: &DbGrant,
    limits: &DbLimits,
    collection: &str,
    doc_id: &str,
    now: chrono::DateTime<Utc>,
) -> Result<Response> {
    let Some(declaration) = declared_collection(declared, collection) else {
        return db_error(
            "unknown_collection",
            "That collection is not declared.",
            422,
        );
    };
    let Ok(expected) = if_version(req)? else {
        return db_error(
            "invalid_if_version",
            "`if_version` must be an integer.",
            400,
        );
    };
    let bytes = req.bytes().await?;
    let patch = match parse_document_body(&bytes) {
        Ok(body) => body,
        Err(refusal) => return body_refusal_response(refusal),
    };
    // Without `if_version` a PATCH is last-write-wins, so losing the
    // read-merge-write race to another writer retries on the fresh row instead
    // of failing a caller that never asked for optimistic concurrency. With
    // `if_version`, a lost race is a real conflict and is reported.
    let attempts = if expected.is_some() { 1 } else { 4 };
    for _ in 0..attempts {
        let Some(current) = fetch_doc(database, row, collection, doc_id).await? else {
            return db_not_found();
        };
        if expected.is_some_and(|version| version != current.version as u64) {
            return conflict(&current, collection);
        }
        let mut merged = match serde_json::from_str::<Value>(&current.body) {
            Ok(Value::Object(map)) => map,
            _ => serde_json::Map::new(),
        };
        for (key, value) in &patch {
            merged.insert(key.clone(), value.clone());
        }
        let encoded = encode_body(&merged);
        if encoded.len() > MAX_BODY_BYTES {
            return body_refusal_response(BodyRefusal::TooLarge);
        }
        let added = bytes_added(encoded.len() as u64, Some(current.body.len() as u64));
        if added > 0 {
            let (docs, org_bytes) = usage(database, row).await?;
            if exceeds_db_quota(limits, docs, org_bytes, 0, added) {
                return quota_response();
            }
        }
        let updated_at = now.to_rfc3339_opts(SecondsFormat::Millis, true);
        let index = declaration
            .index
            .as_deref()
            .and_then(|field| index_value(&merged, field));
        // The write is guarded on the version just read, so a concurrent write
        // between the read and here changes nothing instead of being silently
        // overwritten.
        let result = database
            .prepare("UPDATE artifact_docs SET body = ?, index_value = ?, version = version + 1, updated_at = ?, updated_by = ? WHERE org_id = ? AND artifact_id = ? AND collection = ? AND doc_id = ? AND version = ?")
            .bind(&[
                JsValue::from_str(&encoded),
                index.as_deref().map(JsValue::from_str).unwrap_or_else(JsValue::null),
                JsValue::from_str(&updated_at),
                JsValue::from_str(&grant.viewer),
                JsValue::from_str(&row.org),
                JsValue::from_str(&row.id),
                JsValue::from_str(collection),
                JsValue::from_str(doc_id),
                JsValue::from_f64(current.version),
            ])?
            .run()
            .await?;
        let changed = result.meta()?.and_then(|meta| meta.changes).unwrap_or(0);
        if changed > 0 {
            return written(collection, doc_id, current.version as u64 + 1, &updated_at);
        }
    }
    match fetch_doc(database, row, collection, doc_id).await? {
        Some(latest) => conflict(&latest, collection),
        None => db_not_found(),
    }
}

async fn delete_doc(
    req: &Request,
    database: &worker::D1Database,
    declared: &DbManifest,
    row: &DbArtifactRow,
    collection: &str,
    doc_id: &str,
) -> Result<Response> {
    if declared_collection(declared, collection).is_none() {
        return db_error(
            "unknown_collection",
            "That collection is not declared.",
            422,
        );
    }
    let Ok(expected) = if_version(req)? else {
        return db_error(
            "invalid_if_version",
            "`if_version` must be an integer.",
            400,
        );
    };
    let Some(current) = fetch_doc(database, row, collection, doc_id).await? else {
        // Already gone: a retried DELETE reaches the state it asked for.
        return db_json(
            json!({"id": doc_id, "collection": collection, "deleted": false}),
            200,
        );
    };
    if expected.is_some_and(|version| version != current.version as u64) {
        return conflict(&current, collection);
    }
    // With `if_version` the delete is guarded on it; without, the delete wins
    // over a concurrent write, as last-write-wins promises.
    let mut sql = String::from(
        "DELETE FROM artifact_docs WHERE org_id = ? AND artifact_id = ? AND collection = ? AND doc_id = ?",
    );
    let mut binds = vec![
        JsValue::from_str(&row.org),
        JsValue::from_str(&row.id),
        JsValue::from_str(collection),
        JsValue::from_str(doc_id),
    ];
    if let Some(version) = expected {
        sql.push_str(" AND version = ?");
        binds.push(JsValue::from_f64(version as f64));
    }
    let result = database.prepare(sql).bind(&binds)?.run().await?;
    let changed = result.meta()?.and_then(|meta| meta.changes).unwrap_or(0);
    if changed == 0 && expected.is_some() {
        return match fetch_doc(database, row, collection, doc_id).await? {
            Some(latest) => conflict(&latest, collection),
            // Already gone: the caller's goal is met.
            None => db_json(
                json!({"id": doc_id, "collection": collection, "deleted": true}),
                200,
            ),
        };
    }
    db_json(
        json!({"id": doc_id, "collection": collection, "deleted": true}),
        200,
    )
}

#[derive(Debug, Deserialize)]
struct BatchRequest {
    writes: Vec<BatchWrite>,
}

#[derive(Debug, Deserialize)]
struct BatchWrite {
    op: String,
    collection: String,
    doc_id: String,
    #[serde(default)]
    data: Option<Value>,
    #[serde(default)]
    if_version: Option<u64>,
}

/// `POST /__artfct/db/batch`: up to [`MAX_BATCH_WRITES`] `set` / `delete`
/// writes applied atomically. Every write is validated, and every
/// `if_version` and the quota are checked, before any statement runs, so one
/// failing write applies none; the statements then go in one D1 batch, which
/// is transactional.
async fn batch(
    req: &mut Request,
    database: &worker::D1Database,
    declared: &DbManifest,
    row: &DbArtifactRow,
    grant: &DbGrant,
    limits: &DbLimits,
    now: chrono::DateTime<Utc>,
) -> Result<Response> {
    let bytes = req.bytes().await?;
    if bytes.len() > MAX_BODY_BYTES * 4 {
        return body_refusal_response(BodyRefusal::TooLarge);
    }
    let Ok(request) = serde_json::from_slice::<BatchRequest>(&bytes) else {
        return db_error("invalid_json", "The body is not a valid batch.", 400);
    };
    if request.writes.is_empty() || request.writes.len() > MAX_BATCH_WRITES {
        return db_error(
            "invalid_batch",
            &format!("A batch has 1 to {MAX_BATCH_WRITES} writes."),
            422,
        );
    }
    if !charge_write_rate(
        database,
        &row.id,
        &grant.viewer,
        request.writes.len() as u64,
        now,
    )
    .await?
    {
        return rate_limited_response();
    }
    let updated_at = now.to_rfc3339_opts(SecondsFormat::Millis, true);
    let mut statements = Vec::with_capacity(request.writes.len());
    let mut new_docs = 0u64;
    let mut new_bytes = 0u64;
    let mut seen = std::collections::BTreeSet::new();
    for write in &request.writes {
        let Some(declaration) = declared_collection(declared, &write.collection) else {
            return db_error(
                "unknown_collection",
                "That collection is not declared.",
                422,
            );
        };
        if !crate::artifact_db::valid_doc_id(&write.doc_id)
            || !seen.insert((write.collection.clone(), write.doc_id.clone()))
        {
            return db_error(
                "invalid_batch",
                "Each write needs a valid, distinct doc_id.",
                422,
            );
        }
        let existing = fetch_doc(database, row, &write.collection, &write.doc_id).await?;
        match (&existing, write.if_version) {
            (Some(current), Some(version)) if current.version as u64 != version => {
                return conflict(current, &write.collection);
            }
            (None, Some(version)) if version != 0 => return db_not_found(),
            (Some(current), Some(0)) => return conflict(current, &write.collection),
            _ => {}
        }
        match write.op.as_str() {
            "set" => {
                let Some(data) = &write.data else {
                    return db_error("invalid_batch", "`set` needs `data`.", 422);
                };
                let raw = serde_json::to_vec(data).unwrap_or_default();
                let body = match parse_document_body(&raw) {
                    Ok(body) => body,
                    Err(refusal) => return body_refusal_response(refusal),
                };
                if existing.is_none() {
                    new_docs += 1;
                }
                new_bytes += bytes_added(
                    raw.len() as u64,
                    existing.as_ref().map(|current| current.body.len() as u64),
                );
                let index = declaration
                    .index
                    .as_deref()
                    .and_then(|field| index_value(&body, field));
                statements.push(upsert_statement(
                    database,
                    row,
                    &write.collection,
                    &write.doc_id,
                    &encode_body(&body),
                    index.as_deref(),
                    grant,
                    &updated_at,
                )?);
            }
            "delete" => {
                statements.push(
                    database
                        .prepare("DELETE FROM artifact_docs WHERE org_id = ? AND artifact_id = ? AND collection = ? AND doc_id = ?")
                        .bind(&[
                            JsValue::from_str(&row.org),
                            JsValue::from_str(&row.id),
                            JsValue::from_str(&write.collection),
                            JsValue::from_str(&write.doc_id),
                        ])?,
                );
            }
            _ => {
                return db_error("invalid_batch", "`op` must be `set` or `delete`.", 422);
            }
        }
    }
    if new_docs > 0 || new_bytes > 0 {
        let (docs, org_bytes) = usage(database, row).await?;
        if exceeds_db_quota(limits, docs, org_bytes, new_docs, new_bytes) {
            return quota_response();
        }
    }
    database.batch(statements).await?;
    db_json(json!({"applied": request.writes.len()}), 200)
}

/// Statements that write a manifest's seed rows for a brand new artifact.
/// `INSERT OR IGNORE`, so calling it twice, or for a row that already exists,
/// never overwrites live data. Called on the first deploy only, never on a
/// republish, and a fork copies the manifest (and so the seed) but never the
/// source artifact's live rows.
pub(crate) fn seed_statements(
    database: &worker::D1Database,
    org_slug: &str,
    artifact_id: &str,
    manifest: &DbManifest,
    created_at: &str,
) -> Result<Vec<worker::D1PreparedStatement>> {
    manifest
        .seed
        .iter()
        .map(|seed| {
            let index = manifest
                .collection(&seed.collection)
                .and_then(|declaration| declaration.index.as_deref())
                .and_then(|field| index_value(&seed.body, field));
            database
                .prepare("INSERT OR IGNORE INTO artifact_docs (org_id, artifact_id, collection, doc_id, body, index_value, version, updated_at, updated_by) VALUES (?, ?, ?, ?, ?, ?, 1, ?, 'seed')")
                .bind(&[
                    JsValue::from_str(org_slug),
                    JsValue::from_str(artifact_id),
                    JsValue::from_str(&seed.collection),
                    JsValue::from_str(&seed.doc_id),
                    JsValue::from_str(&encode_body(&seed.body)),
                    index.as_deref().map(JsValue::from_str).unwrap_or_else(JsValue::null),
                    JsValue::from_str(created_at),
                ])
        })
        .collect()
}

/// The statement that removes every row of an artifact, run in the same
/// batch that deletes the artifact.
pub(crate) fn delete_all_statements(
    database: &worker::D1Database,
    org_slug: &str,
    artifact_id: &str,
) -> Result<Vec<worker::D1PreparedStatement>> {
    Ok(vec![
        database
            .prepare("DELETE FROM artifact_docs WHERE org_id = ? AND artifact_id = ?")
            .bind(&[JsValue::from_str(org_slug), JsValue::from_str(artifact_id)])?,
        database
            .prepare("DELETE FROM artifact_db_rate WHERE artifact_id = ?")
            .bind(&[JsValue::from_str(artifact_id)])?,
    ])
}
