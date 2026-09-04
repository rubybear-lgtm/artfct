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
