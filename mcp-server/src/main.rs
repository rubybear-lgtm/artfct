use std::{env, fs, io::Read, path::Path};

use anyhow::{Context, Result};
use clap::Parser;

mod api;
mod artifact_crypto;
mod cli;
mod doctor;
mod mcp;
mod provenance;
mod setup;
mod ui;
mod uninstall;

const DEFAULT_API_BASE_URL: &str = "https://artfct.dev";
type BundleFiles = Vec<(String, Vec<u8>)>;

#[tokio::main]
async fn main() {
    if let Err(e) = run().await {
        ui::error(format!("{e:#}"));
        std::process::exit(1);
    }
}

async fn run() -> Result<()> {
    let cli = cli::Cli::parse();

    match cli.command {
        cli::Command::Deploy(args) => deploy_from_cli(args).await,
        cli::Command::Mcp {
            command: cli::McpCommand::Serve(args),
        } => mcp::run_stdio_server(args.host).await,
        cli::Command::Delete(args) => delete_from_cli(args).await,
        cli::Command::Setup(args) => run_setup(args),
        cli::Command::Uninstall(args) => run_uninstall(args),
        cli::Command::Doctor => {
            let api_base_url = env::var("ARTFCT_API_BASE_URL")
                .unwrap_or_else(|_| DEFAULT_API_BASE_URL.to_string());
            doctor::print_report(&api_base_url);
            Ok(())
        }
        cli::Command::Export(args) => export_from_cli(args).await,
    }
}

async fn export_from_cli(args: cli::ExportArgs) -> Result<()> {
    let token = args
        .org_token
        .or_else(|| env::var("ARTFCT_ORG_TOKEN").ok())
        .ok_or_else(|| anyhow::anyhow!("Export requires ARTFCT_ORG_TOKEN"))?;
    let api_base_url =
        env::var("ARTFCT_API_BASE_URL").unwrap_or_else(|_| DEFAULT_API_BASE_URL.to_string());
    let export =
        api::export_artifacts(&reqwest::Client::new(), &api_base_url, &args.org, &token).await?;
    fs::create_dir_all(args.directory.join("blobs"))?;
    fs::write(
        args.directory.join("metadata.json"),
        serde_json::to_vec_pretty(&export)?,
    )?;
    let http = reqwest::Client::new();
    for (hash, url) in export.blobs {
        let response = http
            .get(url)
            .bearer_auth(&token)
            .send()
            .await
            .with_context(|| format!("Failed to download exported blob {hash}"))?;
        if !response.status().is_success() {
            anyhow::bail!(
                "Artifact Engine rejected exported blob {hash}: {}",
                response.status()
            );
        }
        let bytes = response.bytes().await?.to_vec();
        if artifact_crypto::sha256_hex(&bytes) != hash {
            anyhow::bail!("Exported blob {hash} failed SHA-256 verification");
        }
        write_export_blob(&args.directory, &hash, &bytes)?;
    }
    Ok(())
}

fn write_export_blob(directory: &Path, hash: &str, bytes: &[u8]) -> Result<()> {
    if artifact_crypto::sha256_hex(bytes) != hash {
        anyhow::bail!("Exported blob {hash} failed SHA-256 verification");
    }
    fs::write(directory.join("blobs").join(hash), bytes)?;
    Ok(())
}

async fn delete_from_cli(args: cli::DeleteArgs) -> Result<()> {
    let id = args
        .artifact_id()
        .ok_or_else(|| anyhow::anyhow!("Invalid artifact ID or URL: {}", args.id_or_url))?;

    let api_base_url =
        env::var("ARTFCT_API_BASE_URL").unwrap_or_else(|_| DEFAULT_API_BASE_URL.to_string());

    let pb = ui::spinner(format!("Deleting {id}…"));

    let token = env::var("ARTFCT_ORG_TOKEN").ok();
    match api::delete_artifact(&reqwest::Client::new(), &api_base_url, id, token.as_deref()).await {
        Ok(()) => ui::finish_success(pb, format!("Deleted {id}")),
        Err(e) => {
            ui::finish_error(pb, e.to_string());
            return Err(e);
        }
    }

    Ok(())
}

fn run_setup(args: cli::SetupArgs) -> Result<()> {
    if args.list {
        setup::list_configs();
    } else {
        setup::setup_agents(args.silent)?;
    }

    Ok(())
}

fn run_uninstall(args: cli::UninstallArgs) -> Result<()> {
    uninstall::uninstall(args.silent)
}

async fn deploy_from_cli(args: cli::DeployArgs) -> Result<()> {
    if args.tier == "permanent"
        && matches!(args.input(), cli::DeployInput::File(ref path) if path.is_dir())
    {
        return deploy_permanent_directory(&args).await;
    }
    let html = read_deploy_html(&args)?;
    let api_base_url =
        env::var("ARTFCT_API_BASE_URL").unwrap_or_else(|_| DEFAULT_API_BASE_URL.to_string());
    let cwd = env::current_dir().context("Failed to determine current directory")?;
    let input = args.input();
    let source_path = match &input {
        cli::DeployInput::File(path) => Some(path.as_path()),
        cli::DeployInput::Stdin => None,
    };
    let provenance = provenance::build_cli_provenance(&cwd, source_path);

    if args.tier == "permanent" {
        let token = args
            .org_token
            .clone()
            .or_else(|| env::var("ARTFCT_ORG_TOKEN").ok())
            .ok_or_else(|| anyhow::anyhow!("Permanent artifacts require ARTFCT_ORG_TOKEN"))?;
        let request = artifact_crypto::prepare_permanent_artifact_request(
            &html,
            "public".to_string(),
            provenance,
        )?;
        let pb = ui::spinner("Uploading permanent artifact…");
        let result = api::deploy_permanent_artifact(
            &reqwest::Client::new(),
            &api_base_url,
            &request,
            html.trim().as_bytes(),
            &token,
        )
        .await;
        return match result {
            Ok(artifact) => {
                ui::finish_success(pb, &artifact.url);
                println!("{}", artifact.url);
                Ok(())
            }
            Err(error) => {
                ui::finish_error(pb, error.to_string());
                Err(error)
            }
        };
    }

    let prepared = artifact_crypto::prepare_artifact_request(
        &html,
        artifact_crypto::ArtifactPreparationOptions {
            tier: args.tier.clone(),
            ttl_minutes: args.ttl_minutes,
            preview_blurred: true,
            provenance,
        },
    )?;

    let label = match args.input() {
        cli::DeployInput::File(ref path) => path.display().to_string(),
        cli::DeployInput::Stdin => "stdin".to_string(),
    };
    let pb = ui::spinner(format!("Uploading {label}…"));

    let result =
        api::deploy_artifact(&reqwest::Client::new(), &api_base_url, &prepared.request).await;

    match result {
        Ok(artifact) => {
            let full_url = format!("{}{}", artifact.url, prepared.fragment);
            ui::finish_success(pb, &full_url);
            println!("{full_url}");
        }
        Err(e) => {
            ui::finish_error(pb, e.to_string());
            return Err(e);
        }
    }

    Ok(())
}

async fn deploy_permanent_directory(args: &cli::DeployArgs) -> Result<()> {
    let cli::DeployInput::File(directory) = args.input() else {
        unreachable!("directory bundles require a file argument");
    };
    let entrypoint = args
        .entrypoint
        .clone()
        .unwrap_or_else(|| "index.html".to_string());
    let (manifest, files) = build_manifest_from_directory(&directory, entrypoint)?;
    let cwd = env::current_dir().context("Failed to determine current directory")?;
    let provenance = crate_provenance(&cwd, &directory);
    let request = api::PermanentArtifactRequest {
        mode: "permanent",
        tier: "public".to_string(),
        title: "Permanent artifact".to_string(),
        description: "Published bundle".to_string(),
        thumbnail: "https://artfct.dev/og-image.svg".to_string(),
        preview_blurred: false,
        manifest,
        provenance,
    };
    let token = args
        .org_token
        .clone()
        .or_else(|| env::var("ARTFCT_ORG_TOKEN").ok())
        .ok_or_else(|| anyhow::anyhow!("Permanent artifacts require ARTFCT_ORG_TOKEN"))?;
    let api_base_url =
        env::var("ARTFCT_API_BASE_URL").unwrap_or_else(|_| DEFAULT_API_BASE_URL.to_string());
    let pb = ui::spinner("Uploading permanent bundle…");
    let result = api::deploy_permanent_artifact_files(
        &reqwest::Client::new(),
        &api_base_url,
        &request,
        &files,
        &token,
    )
    .await;
    match result {
        Ok(artifact) => {
            ui::finish_success(pb, &artifact.url);
            println!(
                "{} (uploaded {} file(s), skipped {} file(s))",
                artifact.url,
                artifact.missing_files.len(),
                request
                    .manifest
                    .files
                    .len()
                    .saturating_sub(artifact.missing_files.len())
            );
            Ok(())
        }
        Err(error) => {
            ui::finish_error(pb, error.to_string());
            Err(error)
        }
    }
}

fn build_manifest_from_directory(
    directory: &Path,
    entrypoint: String,
) -> Result<(api::PermanentManifest, BundleFiles)> {
    let mut files = Vec::new();
    collect_bundle_files(directory, directory, &mut files)?;
    files.sort_by(|left, right| left.0.cmp(&right.0));
    let manifest_files = files
        .iter()
        .map(|(path, bytes)| api::PermanentManifestFile {
            path: path.clone(),
            content_type: content_type_for_path(path),
            size_bytes: bytes.len(),
            sha256: artifact_crypto::sha256_hex(bytes),
        })
        .collect();
    Ok((
        api::PermanentManifest {
            entrypoint,
            files: manifest_files,
            external_origins: Vec::new(),
        },
        files,
    ))
}

fn crate_provenance(cwd: &Path, source: &Path) -> provenance::Provenance {
    provenance::build_cli_provenance(cwd, Some(source))
}

fn collect_bundle_files(
    root: &Path,
    current: &Path,
    files: &mut Vec<(String, Vec<u8>)>,
) -> Result<()> {
    let mut entries = fs::read_dir(current)?.collect::<std::result::Result<Vec<_>, _>>()?;
    entries.sort_by_key(|entry| entry.path());
    for entry in entries {
        let path = entry.path();
        if path.is_dir() {
            collect_bundle_files(root, &path, files)?;
        } else if path.is_file() {
            let relative = path
                .strip_prefix(root)?
                .to_string_lossy()
                .replace('\\', "/");
            files.push((relative, fs::read(path)?));
        }
    }
    Ok(())
}

fn content_type_for_path(path: &str) -> String {
    match Path::new(path).extension().and_then(|ext| ext.to_str()) {
        Some("html") => "text/html; charset=utf-8",
        Some("css") => "text/css",
        Some("js") | Some("mjs") => "application/javascript",
        Some("json") => "application/json",
        Some("svg") => "image/svg+xml",
        Some("png") => "image/png",
        Some("jpg") | Some("jpeg") => "image/jpeg",
        Some("woff") => "font/woff",
        Some("woff2") => "font/woff2",
        _ => "application/octet-stream",
    }
    .to_string()
}

fn read_deploy_html(args: &cli::DeployArgs) -> Result<String> {
    match args.input() {
        cli::DeployInput::File(path) => {
            fs::read_to_string(&path).with_context(|| format!("Failed to read {}", path.display()))
        }
        cli::DeployInput::Stdin => {
            let mut html = String::new();
            std::io::stdin()
                .read_to_string(&mut html)
                .context("Failed to read HTML from stdin")?;
            Ok(html)
        }
    }
}

#[cfg(test)]
mod tests {
    use std::fs;

    use super::{build_manifest_from_directory, read_deploy_html};
    use crate::api::{missing_manifest_files, PermanentArtifactRequest};
    use crate::cli::{DeployArgs, DeployInput};
    use crate::provenance::build_cli_provenance;

    fn fixture_dir() -> tempfile::TempDir {
        let directory = tempfile::tempdir().expect("fixture directory");
        fs::create_dir_all(directory.path().join("assets")).expect("assets directory");
        fs::write(directory.path().join("index.html"), b"<html></html>").expect("html fixture");
        fs::write(directory.path().join("assets/app.js"), b"console.log('ok')")
            .expect("js fixture");
        directory
    }

    #[test]
    fn cli_builds_manifest_from_directory() {
        let directory = fixture_dir();
        let (manifest, files) =
            build_manifest_from_directory(directory.path(), "index.html".to_string())
                .expect("manifest should build");
        assert_eq!(files.len(), 2);
        assert_eq!(manifest.files[0].path, "assets/app.js");
        assert_eq!(manifest.files[1].path, "index.html");
        assert_eq!(manifest.entrypoint, "index.html");
    }

    #[test]
    fn cli_skips_files_server_already_has() {
        let directory = fixture_dir();
        let (manifest, _) =
            build_manifest_from_directory(directory.path(), "index.html".to_string())
                .expect("manifest should build");
        let missing = [manifest.files[0].sha256.clone()];
        let request = PermanentArtifactRequest {
            mode: "permanent",
            tier: "public".to_string(),
            title: "Bundle".to_string(),
            description: "Bundle".to_string(),
            thumbnail: "https://example.com/thumbnail.png".to_string(),
            preview_blurred: false,
            manifest,
            provenance: build_cli_provenance(std::path::Path::new("."), None),
        };
        assert_eq!(missing_manifest_files(&request, &missing).unwrap().len(), 1);
    }

    #[test]
    fn cli_ephemeral_path_unchanged() {
        let directory = fixture_dir();
        let path = directory.path().join("index.html");
        let args = DeployArgs {
            file: Some(path.clone()),
            stdin: false,
            tier: "ephemeral".to_string(),
            ttl_minutes: None,
            org_token: None,
            entrypoint: None,
        };
        assert_eq!(read_deploy_html(&args).expect("read html"), "<html></html>");
        assert_eq!(args.input(), DeployInput::File(path.clone()));
    }

    #[test]
    fn entrypoint_override_respected() {
        let directory = fixture_dir();
        let (manifest, _) =
            build_manifest_from_directory(directory.path(), "assets/app.js".to_string())
                .expect("manifest should build");
        assert_eq!(manifest.entrypoint, "assets/app.js");
    }
}
