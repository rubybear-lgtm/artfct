use std::{env, error::Error, process::Command};

use tempfile::NamedTempFile;

#[tokio::test]
#[ignore = "requires a local Worker URL in ARTFCT_INTEGRATION_BASE_URL"]
async fn deploy_against_current_worker_succeeds_with_provenance() -> Result<(), Box<dyn Error>> {
    let Some(base_url) = env::var_os("ARTFCT_INTEGRATION_BASE_URL") else {
        return Ok(());
    };
    let base_url = base_url
        .to_str()
        .ok_or("ARTFCT_INTEGRATION_BASE_URL must be valid UTF-8")?
        .trim_end_matches('/');

    if base_url.to_ascii_lowercase().contains("artfct.dev") {
        return Err("refusing to run integration test against production".into());
    }

    let mut html = NamedTempFile::new()?;
    std::io::Write::write_all(
        &mut html,
        br#"<!doctype html><html><head><title>Provenance integration</title></head><body><h1>Worker integration</h1></body></html>"#,
    )?;

    let output = Command::new(env!("CARGO_BIN_EXE_artfct"))
        .args([
            "deploy",
            html.path().to_str().ok_or("HTML path must be UTF-8")?,
        ])
        .env("ARTFCT_API_BASE_URL", base_url)
        .output()?;

    if !output.status.success() {
        return Err(format!(
            "artfct deploy failed with {}:\n{}",
            output.status,
            String::from_utf8_lossy(&output.stderr)
        )
        .into());
    }

    let stdout = String::from_utf8(output.stdout)?;
    let expected_prefix = format!("{base_url}/p/");
    let full_url = stdout
        .lines()
        .map(str::trim)
        .find(|line| line.starts_with(&expected_prefix) && line.contains('#'))
        .ok_or("deploy output did not contain a share URL with a fragment")?;
    assert!(full_url.starts_with(&expected_prefix));

    let (preview_url, share_fragment) = full_url
        .split_once('#')
        .ok_or("share URL did not include a fragment")?;
    assert!(!share_fragment.is_empty());

    let response = reqwest::get(preview_url).await?;
    assert!(response.status().is_success());
    let body = response.text().await?;
    assert!(body.contains("preview-shell"));
    assert!(body.contains("bodyCiphertextB64"));
    assert!(body.contains("Waiting for the decryption key"));

    Ok(())
}
