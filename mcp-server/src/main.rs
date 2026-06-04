use std::{env, fs, io::Read};

use anyhow::{Context, Result};
use clap::Parser;

mod api;
mod cli;
mod doctor;
mod mcp;
mod setup;
mod uninstall;

const DEFAULT_API_BASE_URL: &str = "https://artfct.dev";

#[tokio::main]
async fn main() -> Result<()> {
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
            print!("{}", doctor::doctor_report(&api_base_url));
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

    api::delete_artifact(&reqwest::Client::new(), &api_base_url, id).await?;

    eprintln!("Deleted artifact {id}");

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
    let artifact = api::deploy_artifact(
        &reqwest::Client::new(),
        &api_base_url,
        &api::CreateArtifactRequest {
            html,
            tier: args.tier,
            ttl_minutes: args.ttl_minutes,
        },
    )
    .await?;

    println!("{}", artifact.url);

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
