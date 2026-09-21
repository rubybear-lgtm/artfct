use std::{
    env, fs,
    io::{Read, Write},
    net::TcpListener,
    path::{Path, PathBuf},
    process::{Command, Stdio},
};

use anyhow::{Context, Result};
use base64::{engine::general_purpose::URL_SAFE_NO_PAD, Engine};
use chrono::Utc;
use dialoguer::Password;
use ring::{
    digest,
    rand::{SecureRandom, SystemRandom},
};
use serde::{Deserialize, Serialize};

const OAUTH_CLIENT_ID: &str = "artfct-cli";
const PLATFORM_CREDENTIAL_ACCOUNT: &str = "artfct";
const PLATFORM_CREDENTIAL_SERVICE: &str = "artfct-cli";

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum CredentialStatus {
    Missing,
    Valid,
    Invalid,
    InsecurePermissions,
}

#[derive(Debug, Clone, Deserialize, Serialize)]
struct StoredCredential {
    token: String,
    api_base_url: String,
    #[serde(default, skip_serializing_if = "Option::is_none")]
    refresh_token: Option<String>,
    #[serde(default, skip_serializing_if = "Option::is_none")]
    expires_at: Option<i64>,
    #[serde(default, skip_serializing_if = "Option::is_none")]
    organization: Option<String>,
}

pub fn login(token: Option<String>, api_base_url: &str) -> Result<()> {
    let token = match token {
        Some(token) => token,
        None => Password::new()
            .with_prompt("Paste your Artfct organization token")
            .interact()
            .context("Failed to read organization token")?,
    };
    let token = token.trim();

    if token.is_empty() {
        anyhow::bail!("Organization token cannot be empty");
    }

    save_secure_credential(
        &credential_path()?,
        &StoredCredential {
            token: token.to_string(),
            api_base_url: api_base_url.trim_end_matches('/').to_string(),
            refresh_token: None,
            expires_at: None,
            organization: None,
        },
    )?;

    eprintln!(
        "Saved Artfct credentials for {}.",
        api_base_url.trim_end_matches('/')
    );
    Ok(())
}

pub async fn oauth_login(api_base_url: &str, organization: Option<&str>) -> Result<()> {
    let listener =
        TcpListener::bind(("127.0.0.1", 0)).context("Could not open a local OAuth callback")?;
    let port = listener.local_addr()?.port();
    let redirect_uri = format!("http://127.0.0.1:{port}/oauth/callback");
    let verifier = random_url_token();
    let challenge =
        URL_SAFE_NO_PAD.encode(digest::digest(&digest::SHA256, verifier.as_bytes()).as_ref());
    let state = random_url_token();
    let authorization_url = build_authorization_url(
        api_base_url.trim_end_matches('/'),
        &redirect_uri,
        &state,
        &challenge,
        organization,
    );

    eprintln!("Opening Artfct sign-in in your browser…");
    eprintln!("If it does not open, visit:\n{authorization_url}");
    open_browser(&authorization_url);

    let callback_request = tokio::task::spawn_blocking(move || receive_callback(listener))
        .await
        .context("OAuth callback task failed")??;
    let (code, returned_state) = parse_callback(&callback_request)?;
    if returned_state != state {
        anyhow::bail!("OAuth state verification failed; refusing to save credentials");
    }

    let tokens = crate::api::exchange_authorization_code(
        &reqwest::Client::new(),
        api_base_url,
        &code,
        OAUTH_CLIENT_ID,
        &redirect_uri,
        &verifier,
    )
    .await?;

    save_secure_credential(
        &credential_path()?,
        &StoredCredential {
            token: tokens.access_token,
            api_base_url: api_base_url.trim_end_matches('/').to_string(),
            refresh_token: Some(tokens.refresh_token),
            expires_at: Some(Utc::now().timestamp() + tokens.expires_in as i64),
            organization: tokens
                .organization
                .or_else(|| organization.map(str::to_string)),
        },
    )?;

    eprintln!(
        "Saved OAuth credentials for {}.",
        api_base_url.trim_end_matches('/')
    );
    Ok(())
}

pub async fn logout(api_base_url: &str) -> Result<()> {
    if let Ok(credential) = load_secure_credential() {
        let client = reqwest::Client::new();
        let revocation_base_url = credential_base_url(&credential, api_base_url);
        if let Some(refresh_token) = credential.refresh_token.as_deref() {
            if let Err(error) = crate::api::revoke_oauth_token(
                &client,
                revocation_base_url,
                refresh_token,
                "refresh_token",
            )
            .await
            {
                eprintln!("Warning: could not revoke the saved OAuth session: {error}");
            }
        } else if let Err(error) = crate::api::revoke_oauth_token(
            &client,
            revocation_base_url,
            &credential.token,
            "access_token",
        )
        .await
        {
            eprintln!("Warning: could not revoke the saved access token: {error}");
        }
    }

    if remove_secure_credential()? {
        eprintln!("Removed Artfct credentials.");
    } else {
        eprintln!("No saved Artfct credentials found.");
    }

    Ok(())
}

pub fn token() -> Option<String> {
    env::var("ARTFCT_ORG_TOKEN")
        .ok()
        .filter(|token| !token.trim().is_empty())
        .or_else(|| {
            load_secure_credential()
                .ok()
                .map(|credential| credential.token)
        })
}

pub fn environment_token_configured() -> bool {
    env::var("ARTFCT_ORG_TOKEN")
        .ok()
        .is_some_and(|token| !token.trim().is_empty())
}

pub fn api_base_url(default: &str) -> String {
    env::var("ARTFCT_API_BASE_URL")
        .ok()
        .filter(|url| !url.trim().is_empty())
        .or_else(|| {
            load_secure_credential()
                .ok()
                .map(|credential| credential.api_base_url)
        })
        .unwrap_or_else(|| default.to_string())
}

pub fn has_credentials() -> bool {
    token().is_some()
}

pub fn credential_status() -> CredentialStatus {
    if platform_credential()
        .transpose()
        .is_some_and(|result| result.is_ok())
    {
        return CredentialStatus::Valid;
    }

    let Ok(path) = credential_path() else {
        return CredentialStatus::Invalid;
    };
    if !path.exists() {
        return CredentialStatus::Missing;
    }

    #[cfg(unix)]
    if fs::metadata(&path)
        .ok()
        .map(|metadata| {
            use std::os::unix::fs::PermissionsExt;

            metadata.permissions().mode() & 0o077 != 0
        })
        .unwrap_or(true)
    {
        return CredentialStatus::InsecurePermissions;
    }

    if load_credential().is_ok() {
        CredentialStatus::Valid
    } else {
        CredentialStatus::Invalid
    }
}

pub async fn access_token(client: &reqwest::Client, api_base_url: &str) -> Result<Option<String>> {
    if let Some(token) = env::var("ARTFCT_ORG_TOKEN")
        .ok()
        .filter(|token| !token.trim().is_empty())
    {
        return Ok(Some(token));
    }

    let Some(mut credential) = load_secure_credential().ok() else {
        return Ok(None);
    };

    let Some(refresh_token) = credential.refresh_token.clone() else {
        return Ok(Some(credential.token));
    };

    let still_valid = credential
        .expires_at
        .is_some_and(|expires_at| expires_at > Utc::now().timestamp() + 30);
    if still_valid {
        return Ok(Some(credential.token));
    }

    let refresh_base_url = credential_base_url(&credential, api_base_url);
    let tokens =
        crate::api::refresh_access_token(client, refresh_base_url, &refresh_token, OAUTH_CLIENT_ID)
            .await
            .context(
                "Saved Artfct OAuth credentials could not be refreshed; run `artfct login --oauth`",
            )?;
    credential.token = tokens.access_token;
    credential.refresh_token = Some(tokens.refresh_token);
    credential.expires_at = Some(Utc::now().timestamp() + tokens.expires_in as i64);
    save_secure_credential(&credential_path()?, &credential)?;

    Ok(Some(credential.token))
}

fn random_url_token() -> String {
    let mut bytes = [0_u8; 32];
    SystemRandom::new()
        .fill(&mut bytes)
        .expect("system randomness must be available");
    URL_SAFE_NO_PAD.encode(bytes)
}

fn credential_base_url<'a>(credential: &'a StoredCredential, fallback: &'a str) -> &'a str {
    if credential.api_base_url.trim().is_empty() {
        fallback
    } else {
        credential.api_base_url.as_str()
    }
}

fn percent_encode(value: &str) -> String {
    value
        .bytes()
        .flat_map(|byte| match byte {
            b'A'..=b'Z' | b'a'..=b'z' | b'0'..=b'9' | b'-' | b'.' | b'_' | b'~' => {
                vec![byte as char]
            }
            byte => format!("%{byte:02X}").chars().collect(),
        })
        .collect()
}

fn build_authorization_url(
    api_base_url: &str,
    redirect_uri: &str,
    state: &str,
    challenge: &str,
    organization: Option<&str>,
) -> String {
    let mut url = format!(
        "{api_base_url}/oauth/authorize?response_type=code&client_id={OAUTH_CLIENT_ID}&redirect_uri={}&scope=artifacts%3Aread%20artifacts%3Adeploy%20collections%3Aread%20collections%3Awrite%20usage%3Aread&state={state}&code_challenge={challenge}&code_challenge_method=S256",
        percent_encode(redirect_uri),
    );
    if let Some(organization) = organization.filter(|value| !value.trim().is_empty()) {
        url.push_str("&team=");
        url.push_str(&percent_encode(organization.trim()));
    }
    url
}

fn receive_callback(listener: TcpListener) -> Result<String, std::io::Error> {
    let (mut stream, _) = listener.accept()?;
    let mut buffer = [0_u8; 8192];
    let length = stream.read(&mut buffer)?;
    let request = String::from_utf8_lossy(&buffer[..length]).to_string();
    stream.write_all(
        b"HTTP/1.1 200 OK\r\nContent-Type: text/html; charset=utf-8\r\nConnection: close\r\n\r\n<h1>Artfct connected</h1><p>You can close this window.</p>",
    )?;
    stream.flush()?;
    Ok(request)
}

fn parse_callback(request: &str) -> Result<(String, String)> {
    let target = request
        .lines()
        .next()
        .and_then(|line| line.split_whitespace().nth(1))
        .context("OAuth callback did not contain an HTTP request target")?;
    let query = target
        .split_once('?')
        .map(|(_, query)| query)
        .context("OAuth callback did not contain query parameters")?;
    let values = query.split('&').filter_map(|part| {
        let (key, value) = part.split_once('=')?;
        Some((key, percent_decode(value)))
    });
    let mut code = None;
    let mut state = None;
    for (key, value) in values {
        match key {
            "code" => code = Some(value),
            "state" => state = Some(value),
            "error" => return Err(anyhow::anyhow!("OAuth authorization failed: {value}")),
            _ => {}
        }
    }
    Ok((
        code.context("OAuth callback did not contain an authorization code")?,
        state.context("OAuth callback did not contain state")?,
    ))
}

fn percent_decode(value: &str) -> String {
    let bytes = value.as_bytes();
    let mut output = Vec::with_capacity(bytes.len());
    let mut index = 0;
    while index < bytes.len() {
        if bytes[index] == b'%' && index + 2 < bytes.len() {
            if let Ok(byte) = u8::from_str_radix(&value[index + 1..index + 3], 16) {
                output.push(byte);
                index += 3;
                continue;
            }
        }
        output.push(if bytes[index] == b'+' {
            b' '
        } else {
            bytes[index]
        });
        index += 1;
    }
    String::from_utf8_lossy(&output).into_owned()
}

fn open_browser(url: &str) {
    #[cfg(target_os = "macos")]
    let command = "open";
    #[cfg(target_os = "linux")]
    let command = "xdg-open";
    #[cfg(target_os = "windows")]
    let command = "start";

    let _ = Command::new(command).arg(url).spawn();
}

fn credential_path() -> Result<PathBuf> {
    let config_root = env::var_os("XDG_CONFIG_HOME")
        .map(PathBuf::from)
        .or_else(|| env::var_os("HOME").map(|home| PathBuf::from(home).join(".config")))
        .context("Could not determine a config directory; set XDG_CONFIG_HOME or HOME")?;

    Ok(config_root.join("artfct").join("credentials.json"))
}

fn load_credential() -> Result<StoredCredential> {
    let path = credential_path()?;
    let content = fs::read_to_string(&path)
        .with_context(|| format!("Failed to read credentials at {}", path.display()))?;

    serde_json::from_str(&content).context("Saved Artfct credentials are invalid")
}

fn load_secure_credential() -> Result<StoredCredential> {
    if let Some(credential) = platform_credential()? {
        return Ok(credential);
    }

    let path = credential_path()?;

    #[cfg(unix)]
    {
        use std::os::unix::fs::PermissionsExt;

        let mode = fs::metadata(&path)?.permissions().mode();
        if mode & 0o077 != 0 {
            anyhow::bail!("Saved Artfct credentials have insecure file permissions");
        }
    }

    load_credential()
}

fn save_secure_credential(path: &Path, credential: &StoredCredential) -> Result<()> {
    match save_platform_credential(credential) {
        Ok(true) => return Ok(()),
        Ok(false) => {}
        Err(error) => {
            eprintln!(
                "Warning: platform credential store unavailable ({error}); using the restricted local fallback."
            );
        }
    }

    save_credential(path, credential)
}

fn remove_secure_credential() -> Result<bool> {
    let removed_from_platform = remove_platform_credential()?;
    let path = credential_path()?;
    let removed_from_file = if path.exists() {
        fs::remove_file(&path).with_context(|| format!("Failed to remove {}", path.display()))?;
        true
    } else {
        false
    };

    Ok(removed_from_platform || removed_from_file)
}

fn platform_credential() -> Result<Option<StoredCredential>> {
    #[cfg(target_os = "macos")]
    {
        let output = match Command::new("security")
            .args([
                "find-generic-password",
                "-a",
                PLATFORM_CREDENTIAL_ACCOUNT,
                "-s",
                PLATFORM_CREDENTIAL_SERVICE,
                "-w",
            ])
            .output()
        {
            Ok(output) => output,
            Err(error) if error.kind() == std::io::ErrorKind::NotFound => return Ok(None),
            Err(error) => return Err(error.into()),
        };

        if !output.status.success() {
            return Ok(None);
        }

        serde_json::from_slice(&output.stdout)
            .context("Platform Artfct credentials are invalid")
            .map(Some)
    }

    #[cfg(target_os = "linux")]
    {
        let output = match Command::new("secret-tool")
            .args([
                "lookup",
                "service",
                PLATFORM_CREDENTIAL_SERVICE,
                "account",
                PLATFORM_CREDENTIAL_ACCOUNT,
            ])
            .output()
        {
            Ok(output) => output,
            Err(error) if error.kind() == std::io::ErrorKind::NotFound => return Ok(None),
            Err(error) => return Err(error.into()),
        };

        if !output.status.success() {
            return Ok(None);
        }

        serde_json::from_slice(&output.stdout)
            .context("Platform Artfct credentials are invalid")
            .map(Some)
    }

    #[cfg(not(any(target_os = "macos", target_os = "linux")))]
    {
        Ok(None)
    }
}

fn save_platform_credential(credential: &StoredCredential) -> Result<bool> {
    let payload = serde_json::to_vec(credential)?;

    #[cfg(target_os = "macos")]
    {
        let mut child = match Command::new("security")
            .args([
                "add-generic-password",
                "-a",
                PLATFORM_CREDENTIAL_ACCOUNT,
                "-s",
                PLATFORM_CREDENTIAL_SERVICE,
                "-U",
                "-w",
            ])
            .stdin(Stdio::piped())
            .stdout(Stdio::null())
            .stderr(Stdio::null())
            .spawn()
        {
            Ok(child) => child,
            Err(error) if error.kind() == std::io::ErrorKind::NotFound => return Ok(false),
            Err(error) => return Err(error.into()),
        };
        child
            .stdin
            .take()
            .context("Could not open Keychain input")?
            .write_all(&payload)?;
        Ok(child.wait()?.success())
    }

    #[cfg(target_os = "linux")]
    {
        let mut child = match Command::new("secret-tool")
            .args([
                "store",
                "--label",
                "Artfct CLI credentials",
                "service",
                PLATFORM_CREDENTIAL_SERVICE,
                "account",
                PLATFORM_CREDENTIAL_ACCOUNT,
            ])
            .stdin(Stdio::piped())
            .stdout(Stdio::null())
            .stderr(Stdio::null())
            .spawn()
        {
            Ok(child) => child,
            Err(error) if error.kind() == std::io::ErrorKind::NotFound => return Ok(false),
            Err(error) => return Err(error.into()),
        };
        child
            .stdin
            .take()
            .context("Could not open Secret Service input")?
            .write_all(&payload)?;
        Ok(child.wait()?.success())
    }

    #[cfg(not(any(target_os = "macos", target_os = "linux")))]
    {
        let _ = payload;
        Ok(false)
    }
}

fn remove_platform_credential() -> Result<bool> {
    #[cfg(target_os = "macos")]
    {
        let output = match Command::new("security")
            .args([
                "delete-generic-password",
                "-a",
                PLATFORM_CREDENTIAL_ACCOUNT,
                "-s",
                PLATFORM_CREDENTIAL_SERVICE,
            ])
            .output()
        {
            Ok(output) => output,
            Err(error) if error.kind() == std::io::ErrorKind::NotFound => return Ok(false),
            Err(error) => return Err(error.into()),
        };
        Ok(output.status.success())
    }

    #[cfg(target_os = "linux")]
    {
        let output = match Command::new("secret-tool")
            .args([
                "clear",
                "service",
                PLATFORM_CREDENTIAL_SERVICE,
                "account",
                PLATFORM_CREDENTIAL_ACCOUNT,
            ])
            .output()
        {
            Ok(output) => output,
            Err(error) if error.kind() == std::io::ErrorKind::NotFound => return Ok(false),
            Err(error) => return Err(error.into()),
        };
        Ok(output.status.success())
    }

    #[cfg(not(any(target_os = "macos", target_os = "linux")))]
    {
        Ok(false)
    }
}

fn save_credential(path: &Path, credential: &StoredCredential) -> Result<()> {
    let parent = path
        .parent()
        .context("Credential path has no parent directory")?;
    fs::create_dir_all(parent).with_context(|| {
        format!(
            "Failed to create credentials directory {}",
            parent.display()
        )
    })?;

    #[cfg(unix)]
    {
        use std::os::unix::fs::PermissionsExt;

        fs::set_permissions(parent, fs::Permissions::from_mode(0o700))?;
    }

    let temporary_path = parent.join(format!(".credentials.{}.tmp", std::process::id()));
    fs::write(&temporary_path, serde_json::to_vec_pretty(credential)?)
        .with_context(|| format!("Failed to write {}", temporary_path.display()))?;

    #[cfg(unix)]
    {
        use std::os::unix::fs::PermissionsExt;

        fs::set_permissions(&temporary_path, fs::Permissions::from_mode(0o600))?;
    }

    fs::rename(&temporary_path, path)
        .with_context(|| format!("Failed to save credentials at {}", path.display()))?;

    Ok(())
}

#[cfg(test)]
mod tests {
    use std::fs;

    use tempfile::tempdir;

    use super::{
        build_authorization_url, credential_base_url, parse_callback, save_credential,
        StoredCredential,
    };

    #[test]
    fn saves_credentials_with_restricted_permissions() {
        let directory = tempdir().expect("temporary directory");
        let path = directory.path().join("credentials.json");
        let credential = StoredCredential {
            token: "secret".to_string(),
            api_base_url: "https://artfct.dev".to_string(),
            refresh_token: None,
            expires_at: None,
            organization: None,
        };

        save_credential(&path, &credential).expect("credentials should save");

        let contents = fs::read_to_string(&path).expect("credentials should be readable");
        assert!(contents.contains("secret"));

        #[cfg(unix)]
        {
            use std::os::unix::fs::PermissionsExt;

            assert_eq!(
                fs::metadata(path).unwrap().permissions().mode() & 0o777,
                0o600
            );
            assert_eq!(
                fs::metadata(directory.path()).unwrap().permissions().mode() & 0o777,
                0o700
            );
        }
    }

    #[test]
    fn parses_loopback_callback_and_rejects_error_responses() {
        let request = "GET /oauth/callback?code=abc123&state=state%2Dvalue HTTP/1.1\r\nHost: 127.0.0.1\r\n\r\n";
        assert_eq!(
            parse_callback(request).expect("callback should parse"),
            ("abc123".to_string(), "state-value".to_string())
        );

        let error = parse_callback("GET /oauth/callback?error=access_denied HTTP/1.1\r\n\r\n")
            .expect_err("OAuth errors should not be accepted as callbacks");
        assert!(error.to_string().contains("access_denied"));
    }

    #[test]
    fn authorization_url_can_pin_a_workspace_without_leaking_raw_query_values() {
        let url = build_authorization_url(
            "https://artfct.dev",
            "http://127.0.0.1:43123/oauth/callback",
            "state-value",
            "challenge-value",
            Some("team/acme west"),
        );

        assert!(url.contains("&team=team%2Facme%20west"));
        assert!(!url.contains("team/acme west"));
        assert!(url.contains("scope=artifacts%3Aread%20artifacts%3Adeploy"));
    }

    #[test]
    fn logout_uses_the_credential_issuer_before_the_runtime_default() {
        let credential = StoredCredential {
            token: "secret".to_string(),
            api_base_url: "https://tenant.artfct.dev".to_string(),
            refresh_token: None,
            expires_at: None,
            organization: None,
        };

        assert_eq!(
            credential_base_url(&credential, "https://artfct.dev"),
            "https://tenant.artfct.dev"
        );
    }

    #[test]
    fn logout_falls_back_for_legacy_credentials_without_an_issuer() {
        let credential = StoredCredential {
            token: "secret".to_string(),
            api_base_url: "  ".to_string(),
            refresh_token: None,
            expires_at: None,
            organization: None,
        };

        assert_eq!(
            credential_base_url(&credential, "https://artfct.dev"),
            "https://artfct.dev"
        );
    }
}
