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

/// Launches an authorization URL; production spawns the platform browser.
type BrowserOpener<'a> = Box<dyn Fn(&str) + Send + 'a>;

/// The parts of a login or logout flow that production takes from global
/// state but a test must supply. The default is production: the platform
/// browser and the platform credential store. An explicit `credential_path`
/// replaces the store entirely, so a flow driven through these seams can
/// never read or write a developer's real Keychain entry or credential file.
#[derive(Default)]
pub struct AuthSeams<'a> {
    /// Launches the authorization URL. Defaults to the platform browser.
    pub open_browser: Option<BrowserOpener<'a>>,
    /// Reads, writes and removes the credential at this path.
    pub credential_path: Option<PathBuf>,
}

impl AuthSeams<'_> {
    fn credential_store(&self) -> CredentialStore<'_> {
        match self.credential_path.as_deref() {
            Some(path) => CredentialStore::File(path),
            None => CredentialStore::Platform,
        }
    }

    fn launch_browser(&self, url: &str) {
        match &self.open_browser {
            Some(opener) => opener(url),
            None => open_browser(url),
        }
    }
}

/// Where one flow reads and writes its credential. Production resolves the
/// platform store with its restricted file fallback; an injected path is the
/// whole store, so the platform store is never consulted.
enum CredentialStore<'a> {
    Platform,
    File(&'a Path),
}

impl CredentialStore<'_> {
    fn load(&self) -> Result<StoredCredential> {
        match self {
            Self::Platform => load_secure_credential(),
            Self::File(path) => load_credential_at(path),
        }
    }

    fn save(&self, credential: &StoredCredential) -> Result<()> {
        match self {
            Self::Platform => save_secure_credential(&credential_path()?, credential),
            Self::File(path) => save_credential(path, credential),
        }
    }

    fn remove(&self) -> Result<bool> {
        match self {
            Self::Platform => remove_secure_credential(),
            Self::File(path) => remove_credential_at(path),
        }
    }
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
    oauth_login_with(api_base_url, organization, AuthSeams::default()).await
}

/// [`oauth_login`] with the browser and the credential destination injectable.
pub async fn oauth_login_with(
    api_base_url: &str,
    organization: Option<&str>,
    seams: AuthSeams<'_>,
) -> Result<()> {
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
    seams.launch_browser(&authorization_url);

    let callback_request = tokio::task::spawn_blocking(move || receive_callback(listener))
        .await
        .context("OAuth callback task failed")??;
    let (code, returned_state) = parse_callback(&callback_request)?;
    verify_callback_state(&state, &returned_state)?;

    let tokens = crate::api::exchange_authorization_code(
        &reqwest::Client::new(),
        api_base_url,
        &code,
        OAUTH_CLIENT_ID,
        &redirect_uri,
        &verifier,
    )
    .await?;

    seams.credential_store().save(&StoredCredential {
        token: tokens.access_token,
        api_base_url: api_base_url.trim_end_matches('/').to_string(),
        refresh_token: Some(tokens.refresh_token),
        expires_at: Some(Utc::now().timestamp() + tokens.expires_in as i64),
        organization: tokens
            .organization
            .or_else(|| organization.map(str::to_string)),
    })?;

    eprintln!(
        "Saved OAuth credentials for {}.",
        api_base_url.trim_end_matches('/')
    );
    Ok(())
}

pub async fn logout(api_base_url: &str) -> Result<()> {
    logout_with(api_base_url, AuthSeams::default()).await
}

/// [`logout`] with the credential destination injectable.
pub async fn logout_with(api_base_url: &str, seams: AuthSeams<'_>) -> Result<()> {
    let store = seams.credential_store();

    if let Ok(credential) = store.load() {
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

    if store.remove()? {
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

/// The CLI's terminal check on the callback: the state it generated must be the
/// state that came back, or the code is not ours and no credential is written.
fn verify_callback_state(expected: &str, returned: &str) -> Result<()> {
    if returned != expected {
        anyhow::bail!("OAuth state verification failed; refusing to save credentials");
    }

    Ok(())
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
    load_credential_at(&credential_path()?)
}

fn load_credential_at(path: &Path) -> Result<StoredCredential> {
    let content = fs::read_to_string(path)
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
    let removed_from_file = remove_credential_at(&credential_path()?)?;

    Ok(removed_from_platform || removed_from_file)
}

fn remove_credential_at(path: &Path) -> Result<bool> {
    if !path.exists() {
        return Ok(false);
    }

    fs::remove_file(path).with_context(|| format!("Failed to remove {}", path.display()))?;

    Ok(true)
}

fn platform_credential() -> Result<Option<StoredCredential>> {
    platform_credential_for(PLATFORM_CREDENTIAL_ACCOUNT, PLATFORM_CREDENTIAL_SERVICE)
}

/// Same lookup as [`platform_credential`], but against an explicit
/// account/service pair. Exists so tests can round-trip through the real
/// platform credential store (Keychain/Secret Service) without touching the
/// shared production entry a developer may be logged in with.
fn platform_credential_for(account: &str, service: &str) -> Result<Option<StoredCredential>> {
    #[cfg(target_os = "macos")]
    {
        let output = match Command::new("security")
            .args(["find-generic-password", "-a", account, "-s", service, "-w"])
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
            .args(["lookup", "service", service, "account", account])
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
        let _ = (account, service);
        Ok(None)
    }
}

fn save_platform_credential(credential: &StoredCredential) -> Result<bool> {
    save_platform_credential_for(
        PLATFORM_CREDENTIAL_ACCOUNT,
        PLATFORM_CREDENTIAL_SERVICE,
        credential,
    )
}

/// Same write as [`save_platform_credential`], but against an explicit
/// account/service pair; see [`platform_credential_for`].
fn save_platform_credential_for(
    account: &str,
    service: &str,
    credential: &StoredCredential,
) -> Result<bool> {
    let payload = serde_json::to_vec(credential)?;

    #[cfg(target_os = "macos")]
    {
        // `security add-generic-password -w` takes the password as the next
        // command-line argument; it does not read it from stdin. Piping the
        // payload to stdin here (as a prior version of this code did) left
        // `-w` with no value, so the Keychain item was created with an empty
        // password and every later read failed to parse as JSON, even
        // though this call reported success.
        let payload =
            String::from_utf8(payload).context("Credential payload was not valid UTF-8")?;
        let output = match Command::new("security")
            .args([
                "add-generic-password",
                "-a",
                account,
                "-s",
                service,
                "-U",
                "-w",
                &payload,
            ])
            .stdin(Stdio::null())
            .stdout(Stdio::null())
            .stderr(Stdio::null())
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
        let mut child = match Command::new("secret-tool")
            .args([
                "store",
                "--label",
                "Artfct CLI credentials",
                "service",
                service,
                "account",
                account,
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
        let _ = (account, service, payload);
        Ok(false)
    }
}

fn remove_platform_credential() -> Result<bool> {
    remove_platform_credential_for(PLATFORM_CREDENTIAL_ACCOUNT, PLATFORM_CREDENTIAL_SERVICE)
}

/// Same removal as [`remove_platform_credential`], but against an explicit
/// account/service pair; see [`platform_credential_for`].
fn remove_platform_credential_for(account: &str, service: &str) -> Result<bool> {
    #[cfg(target_os = "macos")]
    {
        let output = match Command::new("security")
            .args(["delete-generic-password", "-a", account, "-s", service])
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
            .args(["clear", "service", service, "account", account])
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
        let _ = (account, service);
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
    use std::{
        fs,
        io::{Read, Write},
        net::{TcpListener, TcpStream},
        path::Path,
        sync::{
            atomic::{AtomicBool, Ordering},
            mpsc, Arc, Mutex,
        },
        thread,
        time::Duration,
    };

    use anyhow::Result;
    use chrono::Utc;
    use tempfile::tempdir;

    use super::{
        build_authorization_url, credential_base_url, load_credential_at, logout_with,
        oauth_login_with, parse_callback, percent_decode, percent_encode, receive_callback,
        save_credential, verify_callback_state, AuthSeams, StoredCredential,
    };

    #[cfg(target_os = "macos")]
    #[cfg(target_os = "macos")]
    use super::{
        platform_credential_for, remove_platform_credential_for, save_platform_credential_for,
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

    // Regression test for a bug where `security add-generic-password -w` was
    // fed its value over stdin instead of as a command-line argument: the
    // Keychain item was created with an empty password, `artfct login`
    // reported success, and every later read failed. Round-trips through
    // the real macOS Keychain to catch that class of bug directly.
    //
    // Uses a dedicated test-only account/service, never the constants the
    // real CLI logs in with: this test previously shared production's
    // Keychain entry, so running `cargo test` deleted a developer's actual
    // logged-in session as a side effect of this test's own cleanup.
    #[cfg(target_os = "macos")]
    #[test]
    fn round_trips_a_credential_through_the_macos_keychain() {
        const TEST_ACCOUNT: &str = "artfct-test";
        const TEST_SERVICE: &str = "artfct-cli-test";

        let _ = remove_platform_credential_for(TEST_ACCOUNT, TEST_SERVICE);

        let credential = StoredCredential {
            token: "round-trip-secret".to_string(),
            api_base_url: "https://staging.example.test".to_string(),
            refresh_token: Some("refresh-value".to_string()),
            expires_at: Some(1_700_000_000),
            organization: Some("zz-mcp-a".to_string()),
        };

        let saved = save_platform_credential_for(TEST_ACCOUNT, TEST_SERVICE, &credential);
        let loaded = platform_credential_for(TEST_ACCOUNT, TEST_SERVICE);
        let _ = remove_platform_credential_for(TEST_ACCOUNT, TEST_SERVICE);

        let saved = saved.expect("saving to the Keychain should not error");
        if !saved {
            // No Keychain available in this environment (e.g. a locked CI
            // runner); nothing to assert against.
            return;
        }

        let loaded = loaded
            .expect("reading back should not error")
            .expect("a just-saved credential should be found");
        assert_eq!(loaded.token, credential.token);
        assert_eq!(loaded.api_base_url, credential.api_base_url);
        assert_eq!(loaded.refresh_token, credential.refresh_token);
        assert_eq!(loaded.organization, credential.organization);
    }

    #[test]
    fn receive_callback_serves_a_real_loopback_socket_and_returns_the_request() {
        // The CLI's receiver was only ever exercised through a hand-built request
        // string passed to `parse_callback`. This drives the real socket path:
        // bind, accept, read, respond -- so a regression in `receive_callback`
        // itself is caught rather than bypassed.
        let listener = TcpListener::bind(("127.0.0.1", 0)).expect("bind loopback");
        let port = listener.local_addr().expect("local addr").port();

        let receiver = std::thread::spawn(move || receive_callback(listener));

        let mut stream = TcpStream::connect(("127.0.0.1", port)).expect("connect to the receiver");
        stream
            .write_all(
                b"GET /oauth/callback?code=abc123&state=state%2Dvalue HTTP/1.1\r\nHost: 127.0.0.1\r\n\r\n",
            )
            .expect("write the callback request");

        let mut response = String::new();
        stream
            .read_to_string(&mut response)
            .expect("read the response");

        assert!(
            response.starts_with("HTTP/1.1 200 OK"),
            "the receiver must answer the browser so the user is not left on a dead page: {response}"
        );
        assert!(response.contains("Artfct connected"));

        let request = receiver
            .join()
            .expect("receiver thread panicked")
            .expect("receive_callback failed");
        assert_eq!(
            parse_callback(&request).expect("the served request should parse"),
            ("abc123".to_string(), "state-value".to_string())
        );
    }

    #[test]
    fn verify_callback_state_refuses_a_mismatch() {
        verify_callback_state("state-value", "state-value").expect("matching state is accepted");

        let error = verify_callback_state("state-value", "attacker-value")
            .expect_err("a mismatched state must refuse the credential");
        assert!(
            error.to_string().contains("state verification failed"),
            "the refusal must say why: {error}"
        );
    }

    /// Stands in for the hosted authorization server: a hand-rolled HTTP/1.1
    /// responder on a real loopback socket, so the login and logout flows
    /// exchange over the wire rather than through a mocked client. Serves
    /// `/oauth/token` with `token_response` and `/oauth/revoke` with an empty
    /// success, recording every request verbatim for assertions.
    struct StubAuthorizationServer {
        base_url: String,
        requests: Arc<Mutex<Vec<String>>>,
        stop: Arc<AtomicBool>,
        accept_loop: Option<thread::JoinHandle<()>>,
    }

    impl StubAuthorizationServer {
        fn start(token_response: &str) -> Self {
            let listener = TcpListener::bind(("127.0.0.1", 0)).expect("bind the stub server");
            let port = listener.local_addr().expect("stub server address").port();
            listener
                .set_nonblocking(true)
                .expect("the stub server polls for connections");

            let token_response = token_response.to_string();
            let requests = Arc::new(Mutex::new(Vec::new()));
            let stop = Arc::new(AtomicBool::new(false));
            let accept_loop = {
                let requests = Arc::clone(&requests);
                let stop = Arc::clone(&stop);
                thread::spawn(move || {
                    while !stop.load(Ordering::SeqCst) {
                        match listener.accept() {
                            Ok((stream, _)) => {
                                serve_stub_request(stream, &token_response, &requests)
                            }
                            Err(error) if error.kind() == std::io::ErrorKind::WouldBlock => {
                                thread::sleep(Duration::from_millis(5));
                            }
                            Err(_) => break,
                        }
                    }
                })
            };

            Self {
                base_url: format!("http://127.0.0.1:{port}"),
                requests,
                stop,
                accept_loop: Some(accept_loop),
            }
        }

        fn base_url(&self) -> &str {
            &self.base_url
        }

        fn requests(&self) -> Vec<String> {
            self.requests.lock().expect("stub requests").clone()
        }
    }

    impl Drop for StubAuthorizationServer {
        fn drop(&mut self) {
            self.stop.store(true, Ordering::SeqCst);
            if let Some(accept_loop) = self.accept_loop.take() {
                let _ = accept_loop.join();
            }
        }
    }

    fn serve_stub_request(
        mut stream: TcpStream,
        token_response: &str,
        requests: &Mutex<Vec<String>>,
    ) {
        stream
            .set_read_timeout(Some(Duration::from_secs(10)))
            .expect("the stub server bounds its reads");
        let request = read_stub_request(&mut stream);
        requests
            .lock()
            .expect("stub requests")
            .push(request.clone());

        let (status, body) = if request.starts_with("POST /oauth/token ") {
            ("200 OK", token_response)
        } else if request.starts_with("POST /oauth/revoke ") {
            ("200 OK", "{}")
        } else {
            ("404 Not Found", "")
        };
        let _ = stream.write_all(
            format!(
                "HTTP/1.1 {status}\r\nContent-Type: application/json\r\nContent-Length: {}\r\nConnection: close\r\n\r\n{body}",
                body.len()
            )
            .as_bytes(),
        );
        let _ = stream.flush();
    }

    /// Reads one HTTP request off `stream`: the headers, then the bytes the
    /// `Content-Length` header announces.
    fn read_stub_request(stream: &mut TcpStream) -> String {
        let mut request = Vec::new();
        let mut buffer = [0_u8; 1024];
        let mut header_end = None;

        while header_end.is_none() {
            match stream.read(&mut buffer) {
                Ok(0) | Err(_) => break,
                Ok(length) => request.extend_from_slice(&buffer[..length]),
            }
            header_end = request
                .windows(4)
                .position(|window| window == b"\r\n\r\n")
                .map(|index| index + 4);
        }

        let header_end = header_end.unwrap_or(request.len());
        let content_length = String::from_utf8_lossy(&request[..header_end])
            .lines()
            .find_map(|line| {
                let (name, value) = line.split_once(':')?;
                if !name.eq_ignore_ascii_case("content-length") {
                    return None;
                }
                value.trim().parse::<usize>().ok()
            })
            .unwrap_or(0);

        while request.len() - header_end < content_length {
            match stream.read(&mut buffer) {
                Ok(0) | Err(_) => break,
                Ok(length) => request.extend_from_slice(&buffer[..length]),
            }
        }

        String::from_utf8_lossy(&request).into_owned()
    }

    fn authorization_url_parameter(url: &str, name: &str) -> String {
        url.split_once('?')
            .map(|(_, query)| query)
            .and_then(|query| {
                query.split('&').find_map(|pair| {
                    let (key, value) = pair.split_once('=')?;
                    (key == name).then(|| percent_decode(value))
                })
            })
            .unwrap_or_else(|| panic!("the authorization URL has no {name} parameter: {url}"))
    }

    /// Sends a real loopback HTTP GET to the callback listener named in the
    /// authorization URL and waits for the CLI's response, so the connection
    /// is still open when the CLI writes it.
    fn deliver_callback(authorization_url: &str, query: &str) {
        let redirect_uri = authorization_url_parameter(authorization_url, "redirect_uri");
        let (port, route) = redirect_uri
            .split_once("127.0.0.1:")
            .map(|(_, rest)| rest.split_once('/').expect("the redirect URI route"))
            .expect("the redirect URI carries the loopback port");
        let port: u16 = port.parse().expect("the redirect URI port is numeric");

        let mut stream = TcpStream::connect(("127.0.0.1", port))
            .expect("connect to the CLI's callback listener");
        stream
            .set_read_timeout(Some(Duration::from_secs(10)))
            .expect("the callback read is bounded");
        stream
            .write_all(
                format!(
                    "GET /{route}?{query} HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n"
                )
                .as_bytes(),
            )
            .expect("write the callback request");

        let mut response = String::new();
        stream
            .read_to_string(&mut response)
            .expect("read the CLI's callback response");
        assert!(
            response.starts_with("HTTP/1.1 200 OK"),
            "the CLI must answer the callback so a real browser is not left on a dead page: {response}"
        );
    }

    /// Runs one full login with the browser stubbed to an opener that reads the
    /// authorization URL the CLI produced -- that URL names the loopback port
    /// the callback must go to -- and fires the callback from a separate
    /// thread, the way a browser process would. Returns the flow's result and
    /// the authorization URL.
    async fn run_login(
        stub: &StubAuthorizationServer,
        credential_path: &Path,
        organization: Option<&str>,
        callback_query: impl Fn(&str) -> String + Send + Sync + 'static,
    ) -> (Result<()>, String) {
        let authorization_url = Arc::new(Mutex::new(String::new()));
        let url_slot = Arc::clone(&authorization_url);
        let callback_query = Arc::new(callback_query);
        let (callback_done, callback_handles) = mpsc::channel();

        let seams = AuthSeams {
            open_browser: Some(Box::new(move |url: &str| {
                *url_slot.lock().expect("authorization URL slot") = url.to_string();
                let url = url.to_string();
                let callback_query = Arc::clone(&callback_query);
                let handle = thread::spawn(move || {
                    deliver_callback(&url, &callback_query(&url));
                });
                let _ = callback_done.send(handle);
            })),
            credential_path: Some(credential_path.to_path_buf()),
        };

        let result = oauth_login_with(stub.base_url(), organization, seams).await;

        if let Ok(handle) = callback_handles.recv_timeout(Duration::from_secs(10)) {
            handle.join().expect("the callback thread panicked");
        }

        let authorization_url = authorization_url
            .lock()
            .expect("authorization URL slot")
            .clone();

        (result, authorization_url)
    }

    fn read_credential(path: &Path) -> StoredCredential {
        load_credential_at(path).expect("the flow should have written a credential")
    }

    #[tokio::test]
    async fn oauth_login_completes_through_a_real_loopback_callback_and_persists_the_tokens() {
        let stub = StubAuthorizationServer::start(
            r#"{"access_token":"stub-access-token","refresh_token":"stub-refresh-token","expires_in":3600,"organization":"zz-mcp-a"}"#,
        );
        let directory = tempdir().expect("temporary directory");
        let credential_path = directory.path().join("credentials.json");

        let (result, authorization_url) = run_login(&stub, &credential_path, None, |url| {
            format!(
                "code=stub-authorization-code&state={}",
                authorization_url_parameter(url, "state")
            )
        })
        .await;
        result.expect("the login flow should complete");

        let redirect_uri = authorization_url_parameter(&authorization_url, "redirect_uri");
        assert!(
            authorization_url.starts_with(&format!("{}/oauth/authorize?", stub.base_url())),
            "the CLI must open the hosted authorize endpoint: {authorization_url}"
        );
        assert!(
            redirect_uri.starts_with("http://127.0.0.1:"),
            "{redirect_uri}"
        );
        assert!(redirect_uri.ends_with("/oauth/callback"), "{redirect_uri}");
        assert!(authorization_url.contains("code_challenge_method=S256"));

        let credential = read_credential(&credential_path);
        assert_eq!(credential.token, "stub-access-token");
        assert_eq!(
            credential.refresh_token.as_deref(),
            Some("stub-refresh-token")
        );
        assert_eq!(credential.api_base_url, stub.base_url());
        assert_eq!(credential.organization.as_deref(), Some("zz-mcp-a"));
        assert!(
            credential
                .expires_at
                .is_some_and(|expires_at| expires_at > Utc::now().timestamp()),
            "the token's lifetime must be recorded so it can be refreshed before it expires"
        );

        let requests = stub.requests();
        assert_eq!(requests.len(), 1, "one token exchange: {requests:?}");
        assert!(
            requests[0].starts_with("POST /oauth/token "),
            "{}",
            requests[0]
        );
        assert!(requests[0].contains("grant_type=authorization_code"));
        assert!(requests[0].contains("code=stub-authorization-code"));
        assert!(requests[0].contains("client_id=artfct-cli"));
        assert!(requests[0].contains("code_verifier="), "{}", requests[0]);
        assert!(
            requests[0].contains(&format!("redirect_uri={}", percent_encode(&redirect_uri))),
            "the exchange must send back the redirect URI that was authorized: {}",
            requests[0]
        );
    }

    #[tokio::test]
    async fn oauth_login_persists_the_requested_organization_and_asks_authorize_for_it() {
        let stub = StubAuthorizationServer::start(
            r#"{"access_token":"stub-access-token","refresh_token":"stub-refresh-token","expires_in":3600}"#,
        );
        let directory = tempdir().expect("temporary directory");
        let credential_path = directory.path().join("credentials.json");

        let (result, authorization_url) =
            run_login(&stub, &credential_path, Some("team/acme west"), |url| {
                format!(
                    "code=stub-authorization-code&state={}",
                    authorization_url_parameter(url, "state")
                )
            })
            .await;
        result.expect("the login flow should complete");

        assert!(
            authorization_url.contains("&team=team%2Facme%20west"),
            "the authorize request must pin the chosen organization: {authorization_url}"
        );

        let credential = read_credential(&credential_path);
        assert_eq!(credential.organization.as_deref(), Some("team/acme west"));
        assert_eq!(credential.token, "stub-access-token");
    }

    #[tokio::test]
    async fn oauth_login_refuses_a_denied_or_forged_callback_and_writes_no_credential() {
        let stub = StubAuthorizationServer::start(
            r#"{"access_token":"must-not-be-saved","refresh_token":"must-not-be-saved","expires_in":3600}"#,
        );
        let directory = tempdir().expect("temporary directory");

        let denied_path = directory.path().join("denied.json");
        let (denied, _) = run_login(&stub, &denied_path, None, |url| {
            format!(
                "error=access_denied&state={}",
                authorization_url_parameter(url, "state")
            )
        })
        .await;
        let denied = denied.expect_err("a denied authorization must not complete the login");
        assert!(denied.to_string().contains("access_denied"), "{denied}");
        assert!(
            !denied_path.exists(),
            "a denied authorization must not write a credential"
        );

        let forged_path = directory.path().join("forged.json");
        let (forged, _) = run_login(&stub, &forged_path, None, |_| {
            "code=stolen-code&state=attacker-state".to_string()
        })
        .await;
        let forged = forged.expect_err("a callback with a mismatched state must not log in");
        assert!(
            forged.to_string().contains("state verification failed"),
            "{forged}"
        );
        assert!(
            !forged_path.exists(),
            "a forged state must not write a credential"
        );

        assert!(
            stub.requests().is_empty(),
            "a refused callback must never reach the token endpoint: {:?}",
            stub.requests()
        );
    }

    #[tokio::test]
    async fn logout_revokes_the_saved_refresh_token_and_removes_the_credential() {
        let stub = StubAuthorizationServer::start("{}");
        let directory = tempdir().expect("temporary directory");
        let credential_path = directory.path().join("credentials.json");
        save_credential(
            &credential_path,
            &StoredCredential {
                token: "stub-access-token".to_string(),
                api_base_url: stub.base_url().to_string(),
                refresh_token: Some("stub-refresh-token".to_string()),
                expires_at: Some(Utc::now().timestamp() + 3600),
                organization: Some("zz-mcp-a".to_string()),
            },
        )
        .expect("seed the credential");
        assert!(credential_path.exists());

        logout_with(
            "https://must-not-be-used.artfct.invalid",
            AuthSeams {
                open_browser: None,
                credential_path: Some(credential_path.clone()),
            },
        )
        .await
        .expect("logout should complete");

        let requests = stub.requests();
        assert_eq!(requests.len(), 1, "one revocation request: {requests:?}");
        assert!(
            requests[0].starts_with("POST /oauth/revoke "),
            "{}",
            requests[0]
        );
        assert!(
            requests[0].contains("token=stub-refresh-token"),
            "the revoke request must carry the saved refresh token: {}",
            requests[0]
        );
        assert!(
            requests[0].contains("token_type_hint=refresh_token"),
            "{}",
            requests[0]
        );
        assert!(
            !credential_path.exists(),
            "logout must remove the stored credential"
        );
    }
}
