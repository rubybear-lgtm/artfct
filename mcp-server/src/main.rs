use std::{env, fs, io::Read};

use anyhow::{Context, Result};
use clap::Parser;

mod api;
mod cli;
mod doctor;
mod mcp;
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
            command: cli::McpCommand::Serve,
        } => mcp::run_stdio_server().await,
        cli::Command::Delete(args) => delete_from_cli(args).await,
        cli::Command::Setup(args) => run_setup(args),
        cli::Command::Uninstall(args) => run_uninstall(args),
        cli::Command::Doctor => {
            let api_base_url = env::var("ARTFCT_API_BASE_URL")
                .unwrap_or_else(|_| DEFAULT_API_BASE_URL.to_string());
            doctor::print_report(&api_base_url);
            Ok(())
        }
    }
}

async fn delete_from_cli(args: cli::DeleteArgs) -> Result<()> {
    let id = args
        .artifact_id()
        .ok_or_else(|| anyhow::anyhow!("Invalid artifact ID or URL: {}", args.id_or_url))?;

    let api_base_url =
        env::var("ARTFCT_API_BASE_URL").unwrap_or_else(|_| DEFAULT_API_BASE_URL.to_string());

    let pb = ui::spinner(format!("Deleting {id}…"));

    match api::delete_artifact(&reqwest::Client::new(), &api_base_url, id).await {
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

    let label = match args.input() {
        cli::DeployInput::File(ref path) => path.display().to_string(),
        cli::DeployInput::Stdin => "stdin".to_string(),
    };
    let pb = ui::spinner(format!("Uploading {label}…"));

    let result = api::deploy_artifact(
        &reqwest::Client::new(),
        &api_base_url,
        &api::CreateArtifactRequest {
            html,
            tier: args.tier,
            ttl_minutes: args.ttl_minutes,
        },
    )
    .await;

    match result {
        Ok(artifact) => {
            ui::finish_success(pb, &artifact.url);
            println!("{}", artifact.url);
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
