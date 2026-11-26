use super::*;

pub(crate) async fn create_artifact(
    req: &mut Request,
    env: &Env,
    ctx: &worker::Context,
) -> Result<Response> {
    let raw = match req.json::<Value>().await {
        Ok(payload) => payload,
        Err(_) => {
            return json_error(ErrorCode::InvalidJson, "Invalid JSON request body.", 400);
        }
    };

    if raw.get("mode").and_then(Value::as_str) == Some("permanent") {
        let authorization = req.headers().get("Authorization")?;
        return create_permanent_artifact(&raw, authorization.as_deref(), env, ctx).await;
    }

    json_error(
        ErrorCode::ValidationFailed,
        "The mode field must be \"permanent\": anonymous artifact creation has been removed.",
        422,
    )
}

/// Queues `artifact.created` after the response is built. Best effort: the
/// send runs in `waitUntil` and can never change the response. `org` is the
/// verified credential's org.
pub(crate) fn emit_artifact_created(
    ctx: &worker::Context,
    env: &Env,
    org: &str,
    artifact_id: &str,
    tier: ArtifactTier,
) {
    let (Ok(secret), Ok(url)) = (
        env.var(events::EVENT_SECRET_ENV),
        env.var(events::EVENT_URL_ENV),
    ) else {
        return;
    };
    let tier = match tier {
        ArtifactTier::Public => "public",
        ArtifactTier::Secure => "secure",
        ArtifactTier::Private => "private",
    };
    let now = Utc::now();
    let event = events::build_event(
        &secret.to_string(),
        "artifact.created",
        org,
        &now.to_rfc3339_opts(SecondsFormat::Secs, true),
        now.timestamp(),
        serde_json::json!({"artifact_id": artifact_id, "tier": tier}),
    );
    let url = url.to_string();
    ctx.wait_until(async move { events::send(&url, event).await });
}

/// Queues `artifact.version_created` after a version has been promoted to
/// current. Emitted only on promotion, never when a version is still pending
/// its uploads. Best effort: the send runs in `waitUntil` and can never change
/// the response. `org` is the artifact's owning org and `editor_org` the
/// credential's org, so Laravel can audit a cross-org Public + edit write;
/// `created_by` is the publisher's user id.
#[allow(
    clippy::too_many_arguments,
    reason = "one named event builder; every field is part of the audit payload"
)]
pub(crate) fn emit_artifact_version_created(
    ctx: &worker::Context,
    env: &Env,
    org: &str,
    artifact_id: &str,
    version: u32,
    tier: ArtifactTier,
    editor_org: &str,
    created_by: &str,
) {
    let (Ok(secret), Ok(url)) = (
        env.var(events::EVENT_SECRET_ENV),
        env.var(events::EVENT_URL_ENV),
    ) else {
        return;
    };
    let tier = match tier {
        ArtifactTier::Public => "public",
        ArtifactTier::Secure => "secure",
        ArtifactTier::Private => "private",
    };
    let now = Utc::now();
    let event = events::build_event(
        &secret.to_string(),
        "artifact.version_created",
        org,
        &now.to_rfc3339_opts(SecondsFormat::Secs, true),
        now.timestamp(),
        serde_json::json!({
            "artifact_id": artifact_id,
            "version": version,
            "tier": tier,
            "editor_org": editor_org,
            "created_by": created_by,
        }),
    );
    let url = url.to_string();
    ctx.wait_until(async move { events::send(&url, event).await });
}

/// Queues `artifact.sharing_changed` after the sharing update is committed.
/// Best effort, in `waitUntil`, so it never changes the response. `org` is the
/// artifact's owning org; `actor_user_id` is the credential that changed it.
/// `from`/`to` use the API sharing names (`private`/`team`/`public`).
#[allow(
    clippy::too_many_arguments,
    reason = "one named event builder; every field is part of the audit payload"
)]
pub(crate) fn emit_artifact_sharing_changed(
    ctx: &worker::Context,
    env: &Env,
    org: &str,
    artifact_id: &str,
    from: &str,
    to: &str,
    from_edit_access: &str,
    to_edit_access: &str,
    actor_user_id: &str,
) {
    let (Ok(secret), Ok(url)) = (
        env.var(events::EVENT_SECRET_ENV),
        env.var(events::EVENT_URL_ENV),
    ) else {
        return;
    };
    let now = Utc::now();
    let event = events::build_event(
        &secret.to_string(),
        "artifact.sharing_changed",
        org,
        &now.to_rfc3339_opts(SecondsFormat::Secs, true),
        now.timestamp(),
        serde_json::json!({
            "artifact_id": artifact_id,
            "from": from,
            "to": to,
            "from_edit_access": from_edit_access,
            "to_edit_access": to_edit_access,
            "actor_user_id": actor_user_id,
        }),
    );
    let url = url.to_string();
    ctx.wait_until(async move { events::send(&url, event).await });
}

/// Notifies Laravel after a permanent artifact has been hard-deleted so its
/// derived search index can be removed without delaying the delete response.
pub(crate) fn emit_artifact_deleted(
    ctx: &worker::Context,
    env: &Env,
    org: &str,
    artifact_id: &str,
) {
    let (Ok(secret), Ok(url)) = (
        env.var(events::EVENT_SECRET_ENV),
        env.var(events::EVENT_URL_ENV),
    ) else {
        return;
    };
    let now = Utc::now();
    let event = events::build_artifact_deleted_event(
        &secret.to_string(),
        org,
        artifact_id,
        &now.to_rfc3339_opts(SecondsFormat::Secs, true),
        now.timestamp(),
    );
    let url = url.to_string();
    ctx.wait_until(async move { events::send(&url, event).await });
}

/// Queues `artifact.viewed` after a permanent artifact is served. The event
/// carries only the owning org, artifact id, an optional verified viewer id,
/// and — only when no verified viewer id was resolved — a daily-salted
/// pseudonymous `viewer_key` (see `artifact_routes::derive_visitor_key`) so
/// Laravel can still count distinct anonymous viewers (Slack opens, shared
/// links) for spec 16's usage ranking (RUB-314). It never includes content,
/// bearer tokens, or query-string access tokens.
///
/// This function never dedups: every successful view queues an event, even
/// a reload by the same visitor a second later. "One event per visitor per
/// artifact per day" is enforced Laravel-side instead, on a unique index
/// over `(team_id, artifact_id, viewer_key, event_type)` — `viewer_key`
/// already changes once per UTC day, so that uniqueness constraint alone
/// gives the per-day dedup, and (unlike a Worker-side skip keyed on
/// in-memory or KV state) it is naturally idempotent against redelivery of
/// this same event, which the Worker->Laravel channel already has to
/// tolerate elsewhere (see `worker_events_received`'s replay handling).
pub(crate) fn emit_artifact_viewed(
    ctx: &worker::Context,
    env: &Env,
    org: &str,
    artifact_id: &str,
    viewer_user_id: Option<&str>,
    viewer_key: Option<&str>,
) {
    let (Ok(secret), Ok(url)) = (
        env.var(events::EVENT_SECRET_ENV),
        env.var(events::EVENT_URL_ENV),
    ) else {
        return;
    };
    let now = Utc::now();
    let event = events::build_event(
        &secret.to_string(),
        "artifact.viewed",
        org,
        &now.to_rfc3339_opts(SecondsFormat::Secs, true),
        now.timestamp(),
        serde_json::json!({
            "artifact_id": artifact_id,
            "viewer_user_id": viewer_user_id,
            "viewer_key": viewer_key,
            "source": "worker_preview",
        }),
    );
    let url = url.to_string();
    ctx.wait_until(async move { events::send(&url, event).await });
}

pub(crate) async fn resolve_artifact(
    path: &str,
    req: &Request,
    env: &Env,
    ctx: &worker::Context,
) -> Result<Response> {
    let suffix = path.trim_start_matches("/p/");
    let (artifact_id, requested_path) =
        suffix.split_once('/').map_or((suffix, None), |(id, file)| {
            (id, (!file.is_empty()).then_some(file))
        });
    if store::is_permanent_id(artifact_id) {
        // A leading `v:{n}` segment selects a specific version: `/p/{id}/v:2/`
        // and `/p/{id}/v:2/a/b.css`. An invalid segment (`v:0`, `v:x`) parses
        // to `(None, None)`, which only a version-shaped request can produce,
        // so it is a 404 rather than a fallthrough to the current version.
        let (version, version_path) = match requested_path {
            Some(path) => split_version_segment(path),
            None => (None, None),
        };
        if requested_path.is_some() && version.is_none() && version_path.is_none() {
            return expired_response();
        }
        return resolve_permanent_artifact(artifact_id, version_path, version, req, env, ctx).await;
    }
    expired_response()
}

/// Splits a `/p/{id}/` remainder into an optional version and the file path
/// within that version. Manifest paths may not contain a colon (see the
/// contract's `RelativePath`), so a leading `v:` segment can never collide
/// with a real bundle path.
///
/// - `v:2/` -> `(Some(2), None)` (the version's entrypoint)
/// - `v:2/a/b.css` -> `(Some(2), Some("a/b.css"))`
/// - `v:0/`, `v:x/` -> `(None, None)`, the caller's not-found sentinel
/// - `index.html` -> `(None, Some("index.html"))`
///
/// Pure so the parsing is unit-tested without a live Worker.
pub(crate) fn split_version_segment(rest: &str) -> (Option<u32>, Option<&str>) {
    let Some(after) = rest.strip_prefix("v:") else {
        return (None, Some(rest));
    };
    let (number, remainder) = after
        .split_once('/')
        .map_or((after, ""), |(number, remainder)| (number, remainder));
    let Ok(version) = number.parse::<u32>() else {
        return (None, None);
    };
    if version == 0 {
        return (None, None);
    }
    (Some(version), (!remainder.is_empty()).then_some(remainder))
}

#[derive(Debug, Deserialize)]
pub(crate) struct PermanentHashRow {
    #[serde(default)]
    pub(crate) legal_hold: i64,
}

#[derive(Debug, Deserialize)]
pub(crate) struct PresenceRow {
    #[allow(dead_code)]
    present: i64,
}
#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn split_version_segment_reads_the_three_shapes() {
        assert_eq!(split_version_segment("v:2/"), (Some(2), None));
        assert_eq!(
            split_version_segment("v:2/a/b.css"),
            (Some(2), Some("a/b.css"))
        );
        assert_eq!(
            split_version_segment("index.html"),
            (None, Some("index.html"))
        );
        assert_eq!(split_version_segment("a/b.css"), (None, Some("a/b.css")));
    }

    #[test]
    fn split_version_segment_treats_invalid_versions_as_not_found() {
        assert_eq!(split_version_segment("v:0/"), (None, None));
        assert_eq!(split_version_segment("v:0"), (None, None));
        assert_eq!(split_version_segment("v:x/"), (None, None));
        assert_eq!(split_version_segment("v:/"), (None, None));
        assert_eq!(
            split_version_segment("v:99999999999999999999/"),
            (None, None)
        );
    }
}
