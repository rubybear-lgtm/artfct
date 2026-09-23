//! The authenticated onboarding path — `setup`, then `login`, then `doctor` —
//! covered as one flow instead of three isolated stages.
//!
//! Test-only (`#[cfg(test)] mod onboarding;` in `main.rs`): the chain has to
//! reach the crate's own seams, which an integration test in `tests/` cannot
//! from a binary crate.

use std::{
    fs,
    path::{Path, PathBuf},
};

use serde_json::{json, Value};
use tempfile::tempdir;

use crate::{
    auth::{
        self,
        tests::{authorization_url_parameter, run_login, StubAuthorizationServer},
    },
    doctor::{CredentialState, DoctorEnvironment, DoctorReport},
    setup::{self, AgentConfig, ConfigFormat},
};

#[tokio::test]
async fn setup_login_and_doctor_carry_the_authenticated_onboarding_path_end_to_end() {
    let workspace = tempdir().expect("temporary workspace");
    let home = workspace.path().join("home");
    fs::create_dir_all(&home).expect("create the temporary home");
    let binary = workspace.path().join("bin").join("artfct");
    fs::create_dir_all(binary.parent().expect("the binary has a parent"))
        .expect("create the binary directory");
    fs::write(&binary, "#!/bin/sh\n").expect("the configured binary has to exist");
    let credential_path = workspace
        .path()
        .join("config")
        .join("artfct")
        .join("credentials.json");

    // The stores this flow must never reach: snapshotted, so a later
    // comparison can tell a write from an absence.
    let real_credential_path =
        auth::credential_path().expect("this machine has a config directory");
    let real_credential_before = snapshot(&real_credential_path);
    let platform_touches_before = auth::platform_store_touches();

    // ── 1. setup ─────────────────────────────────────────────────────────────

    let discovered = setup::discover_agents_in(Some(&home));
    let expected_agent_paths: Vec<PathBuf> =
        discovered.iter().map(|agent| agent.path.clone()).collect();
    let agents: Vec<AgentConfig> = discovered
        .into_iter()
        .filter(|agent| agent.path.starts_with(&home))
        .collect();
    assert!(!agents.is_empty(), "the temporary home discovers no agents");
    for agent in &agents {
        assert!(
            !agent.path.exists(),
            "{} must start unconfigured",
            agent.path.display()
        );
    }

    let configured =
        setup::setup_agents_with(&agents, &binary, true).expect("configure the agents");
    assert_eq!(
        configured,
        agents.len(),
        "every discovered agent should be configured"
    );
    for agent in &agents {
        assert_mcp_entry(agent, &binary);
    }

    // ── 2. login ─────────────────────────────────────────────────────────────

    let stub = StubAuthorizationServer::start(
        r#"{"access_token":"onboarding-access-token","refresh_token":"onboarding-refresh-token","expires_in":3600,"organization":"zz-mcp-a"}"#,
    );

    let (login, authorization_url) = run_login(&stub, &credential_path, None, |url| {
        format!(
            "code=onboarding-authorization-code&state={}",
            authorization_url_parameter(url, "state")
        )
    })
    .await;
    login.expect("the login flow should complete");
    assert!(
        authorization_url.starts_with(&format!("{}/oauth/authorize?", stub.base_url())),
        "the injected opener stands in for the platform browser: {authorization_url}"
    );

    let credential: Value = serde_json::from_str(
        &fs::read_to_string(&credential_path).expect("login must persist a credential"),
    )
    .expect("the persisted credential is JSON");
    assert_eq!(credential["token"], json!("onboarding-access-token"));
    assert_eq!(
        credential["refresh_token"],
        json!("onboarding-refresh-token")
    );
    assert_eq!(credential["organization"], json!("zz-mcp-a"));
    assert_eq!(credential["api_base_url"], json!(stub.base_url()));
    assert!(
        stub.requests()
            .iter()
            .any(|request| request.starts_with("POST /oauth/token ")),
        "the credential must come from a token exchange: {:?}",
        stub.requests()
    );

    // ── 3. doctor ────────────────────────────────────────────────────────────

    let environment = DoctorEnvironment {
        credential_path: Some(&credential_path),
        home: Some(&home),
    };
    let report = DoctorReport::collect_with(stub.base_url(), &environment);

    assert_eq!(
        report.saved_credential,
        CredentialState::Valid,
        "the doctor must see the credential the login persisted"
    );
    assert_eq!(
        report.saved_credential.rendered(),
        "configured (value hidden)"
    );
    assert_eq!(json!(report.api_base_url), credential["api_base_url"]);

    let reported_agent_paths: Vec<PathBuf> = report
        .agents
        .iter()
        .map(|agent| agent.path.clone())
        .collect();
    assert_eq!(
        reported_agent_paths, expected_agent_paths,
        "the doctor must inspect the injected home, not this machine's"
    );
    for agent in &agents {
        let reported = report
            .agents
            .iter()
            .find(|reported| reported.path == agent.path)
            .unwrap_or_else(|| {
                panic!(
                    "the doctor does not report {} at {}",
                    agent.name,
                    agent.path.display()
                )
            });
        assert!(
            reported.configured,
            "setup wrote {} but the doctor reports it as not configured",
            agent.path.display()
        );
        assert_eq!(reported.rendered(), "config found", "{}", agent.name);
    }

    // The link that makes this one chain rather than three tests: the doctor's
    // verdict is a reading of the file the login wrote, so removing that file
    // flips the verdict. A doctor reporting anything it did not read — a fixed
    // state, or a platform lookup — could not flip.
    fs::remove_file(&credential_path).expect("remove the credential the doctor just read");
    assert_eq!(
        DoctorReport::collect_with(stub.base_url(), &environment).saved_credential,
        CredentialState::Missing
    );

    // ── the real stores, untouched ───────────────────────────────────────────

    assert_ne!(
        credential_path, real_credential_path,
        "the chain must be driven through an injected credential path"
    );
    assert_eq!(
        snapshot(&real_credential_path),
        real_credential_before,
        "the chain must not create, change or remove {}",
        real_credential_path.display()
    );
    assert_eq!(
        auth::platform_store_touches(),
        platform_touches_before,
        "the chain must not read or write the platform credential store (macOS Keychain, Linux Secret Service)"
    );
}

/// The MCP entry a client will execute has to name the binary the user
/// installed and carry the server command, in whichever format that client
/// reads. Parsed back from disk rather than trusted from `setup`'s return
/// value.
fn assert_mcp_entry(agent: &AgentConfig, binary: &Path) {
    let binary = binary.to_str().expect("the binary path is utf-8");
    let host = agent.host.expect("a home config names its agent host");
    let content = fs::read_to_string(&agent.path)
        .unwrap_or_else(|error| panic!("setup must write {}: {error}", agent.path.display()));

    let args = match agent.format {
        ConfigFormat::JsonMcpServers => {
            let parsed: Value = serde_json::from_str(&content).expect("the config is valid JSON");
            assert_eq!(
                parsed["mcpServers"]["artfct"]["command"],
                json!(binary),
                "{} must execute the installed binary",
                agent.name
            );
            parsed["mcpServers"]["artfct"]["args"].clone()
        }
        ConfigFormat::JsonOpenCode => {
            let parsed: Value = serde_json::from_str(&content).expect("the config is valid JSON");
            assert_eq!(parsed["mcp"]["artfct"]["type"], json!("local"));
            assert_eq!(parsed["mcp"]["artfct"]["enabled"], json!(true));
            let command = parsed["mcp"]["artfct"]["command"]
                .as_array()
                .unwrap_or_else(|| panic!("{} must hold the command as an array", agent.name))
                .clone();
            assert_eq!(
                command[0],
                json!(binary),
                "{} must execute the installed binary",
                agent.name
            );
            Value::Array(command[1..].to_vec())
        }
        ConfigFormat::Toml => {
            let parsed: toml::Value = toml::from_str(&content).expect("the config is valid TOML");
            assert_eq!(
                parsed["mcp_servers"]["artfct"]["command"].as_str(),
                Some(binary),
                "{} must execute the installed binary",
                agent.name
            );
            Value::Array(
                parsed["mcp_servers"]["artfct"]["args"]
                    .as_array()
                    .unwrap_or_else(|| panic!("{} must hold the args as an array", agent.name))
                    .iter()
                    .map(|arg| json!(arg.as_str().expect("an argument is a string")))
                    .collect(),
            )
        }
    };

    assert_eq!(
        args,
        json!(["mcp", "serve", "--host", host]),
        "{} must start the MCP server for its own host",
        agent.name
    );
}

/// What a file looks like right now — contents, and (on unix) permissions — so
/// a later comparison can tell whether anything about it moved. `None` means
/// the file is not there.
fn snapshot(path: &Path) -> Option<(Vec<u8>, u32)> {
    let bytes = fs::read(path).ok()?;

    #[cfg(unix)]
    let mode = {
        use std::os::unix::fs::PermissionsExt;

        fs::metadata(path).ok()?.permissions().mode()
    };
    #[cfg(not(unix))]
    let mode = 0;

    Some((bytes, mode))
}
