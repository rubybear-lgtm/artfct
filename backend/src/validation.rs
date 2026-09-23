use super::*;

pub(crate) fn ephemeral_manifest_is_invalid(raw: &Value) -> bool {
    raw.get("mode").and_then(Value::as_str) != Some("permanent") && raw.get("manifest").is_some()
}

pub(crate) fn is_valid_relative_path(path: &str) -> bool {
    !path.is_empty()
        && path.len() <= MAX_PATH_BYTES
        && !path.starts_with('/')
        && !path.contains('\\')
        && !path.contains(':')
        && !path
            .split('/')
            .any(|segment| segment == ".." || segment.is_empty())
}

pub(crate) fn normalized_content_type(value: &str) -> String {
    let value = value.trim().to_ascii_lowercase();
    if matches!(
        value.as_str(),
        "text/html"
            | "text/html; charset=utf-8"
            | "text/css"
            | "text/javascript"
            | "application/javascript"
            | "application/json"
            | "application/wasm"
            | "image/svg+xml"
            | "image/png"
            | "image/jpeg"
            | "image/gif"
            | "image/webp"
            | "font/woff"
            | "font/woff2"
            | "font/ttf"
            | "font/otf"
    ) {
        value
    } else {
        "application/octet-stream".to_string()
    }
}

pub(crate) fn uploaded_file_error(
    expected_hash: &str,
    expected_size: usize,
    bytes: &[u8],
) -> Option<ErrorCode> {
    if bytes.len() > MAX_FILE_BYTES {
        return Some(ErrorCode::BundleTooLarge);
    }
    if store::content_hash(bytes) != expected_hash {
        return Some(ErrorCode::HashMismatch);
    }
    (bytes.len() != expected_size).then_some(ErrorCode::ValidationFailed)
}

pub(crate) fn missing_manifest_files(
    manifest: &PermanentManifest,
    present: &[bool],
) -> Vec<String> {
    let mut missing = manifest
        .files
        .iter()
        .zip(present.iter().copied())
        .filter(|(_, is_present)| !*is_present)
        .map(|(file, _)| file.sha256.clone())
        .collect::<Vec<_>>();
    missing.sort();
    missing.dedup();
    missing
}

#[allow(dead_code)]
pub(crate) fn manifest_is_complete(manifest: &PermanentManifest, uploaded_paths: &[&str]) -> bool {
    manifest
        .files
        .iter()
        .all(|file| uploaded_paths.contains(&file.path.as_str()))
}

pub(crate) fn upload_expired(expires_at: Option<&str>, now: chrono::DateTime<Utc>) -> bool {
    expires_at
        .and_then(|value| chrono::DateTime::parse_from_rfc3339(value).ok())
        .is_some_and(|value| value.with_timezone(&Utc) <= now)
}

pub(crate) fn validate_permanent_manifest(
    raw: &Value,
) -> Result<(PermanentManifest, String), ErrorCode> {
    let Some(manifest) = raw.get("manifest").and_then(Value::as_object) else {
        return Err(ErrorCode::ValidationFailed);
    };
    let Some(entrypoint) = manifest.get("entrypoint").and_then(Value::as_str) else {
        return Err(ErrorCode::EntrypointMissing);
    };
    if !is_valid_relative_path(entrypoint) {
        return Err(ErrorCode::InvalidPath);
    }
    let Some(files) = manifest.get("files").and_then(Value::as_array) else {
        return Err(ErrorCode::ValidationFailed);
    };
    if files.is_empty() {
        return Err(ErrorCode::ValidationFailed);
    }
    if files.len() > MAX_BUNDLE_FILES {
        return Err(ErrorCode::FileCountExceeded);
    }
    let mut external_origins = manifest
        .get("external_origins")
        .and_then(Value::as_array)
        .ok_or(ErrorCode::ValidationFailed)?
        .iter()
        .map(|origin| {
            origin
                .as_str()
                .filter(|value| {
                    value.starts_with("https://") && !value.chars().any(char::is_whitespace)
                })
                .map(str::to_string)
                .ok_or(ErrorCode::ValidationFailed)
        })
        .collect::<Result<Vec<_>, _>>()?;
    external_origins.sort();
    external_origins.dedup();
    let unsafe_eval = match manifest.get("unsafe_eval") {
        None => false,
        Some(Value::Bool(value)) => *value,
        Some(_) => return Err(ErrorCode::ValidationFailed),
    };
    let mut paths = std::collections::HashSet::new();
    let mut total = 0usize;
    let mut parsed = Vec::with_capacity(files.len());
    for file in files {
        let path = file.get("path").and_then(Value::as_str).unwrap_or_default();
        if !is_valid_relative_path(path) {
            return Err(ErrorCode::InvalidPath);
        }
        if !paths.insert(path.to_string()) {
            return Err(ErrorCode::DuplicatePath);
        }
        let size_bytes = file
            .get("size_bytes")
            .and_then(Value::as_u64)
            .and_then(|size| usize::try_from(size).ok())
            .ok_or(ErrorCode::ValidationFailed)?;
        if size_bytes > MAX_FILE_BYTES {
            return Err(ErrorCode::BundleTooLarge);
        }
        total = total.saturating_add(size_bytes);
        if total > MAX_BUNDLE_BYTES {
            return Err(ErrorCode::BundleTooLarge);
        }
        let sha256 = file
            .get("sha256")
            .and_then(Value::as_str)
            .unwrap_or_default();
        if sha256.len() != 64
            || !sha256
                .bytes()
                .all(|byte| byte.is_ascii_hexdigit() && !byte.is_ascii_uppercase())
        {
            return Err(ErrorCode::ValidationFailed);
        }
        let content_type = file
            .get("content_type")
            .and_then(Value::as_str)
            .unwrap_or("application/octet-stream");
        parsed.push(PermanentManifestFile {
            path: path.to_string(),
            content_type: normalized_content_type(content_type),
            size_bytes,
            sha256: sha256.to_string(),
        });
    }
    if !paths.contains(entrypoint) {
        return Err(ErrorCode::EntrypointMissing);
    }
    parsed.sort_by(|left, right| left.path.cmp(&right.path));
    let manifest = PermanentManifest {
        entrypoint: entrypoint.to_string(),
        files: parsed,
        external_origins,
        unsafe_eval,
    };
    let canonical = serde_json::to_vec(&manifest).map_err(|_| ErrorCode::ValidationFailed)?;
    Ok((manifest, store::content_hash(&canonical)))
}

/// A string field trimmed to `max` characters; empty or non-string is `None`.
pub(crate) fn clipped_text(value: Option<&Value>, max: usize) -> Option<String> {
    let text = value?.as_str()?.trim();
    (!text.is_empty()).then(|| text.chars().take(max).collect())
}
